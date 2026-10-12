<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\Exceptions\ExecutionLimitException;
use BashBox\ExecResult;
use BashBox\Limits;
use Generator;
use JsonException;
use RuntimeException;
use stdClass;

final class Jq_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'jq';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $variables = [];
        $remaining = [];
        $end = false;
        $skip = 0;

        foreach ($args as $index => $arg) {
            if ($index < $skip) {
                continue;
            }

            if (! $end && in_array($arg, ['--arg', '--argjson'], true)) {
                $name = $args[$index + 1] ?? '';
                $value = $args[$index + 2] ?? null;

                if (preg_match('/^[A-Za-z_][A-Za-z_0-9]*$/', $name) !== 1 || $value === null) {
                    return $this->usageError($arg.' requires a variable name and value', 2);
                }

                try {
                    if ($arg === '--argjson') {
                        $values = $this->inputs($value, $commandContext->limits);
                        $values->rewind();

                        if (! $values->valid()) {
                            return $this->usageError('invalid JSON value for --argjson', 2);
                        }

                        $decoded = $values->current();
                        $values->next();

                        if ($values->valid()) {
                            return $this->usageError('invalid JSON value for --argjson', 2);
                        }

                        if (! array_key_exists($name, $variables)) {
                            $variables[$name] = $decoded;
                        }
                    } else {
                        if (strlen($value) > $commandContext->limits->maxStringLength) {
                            throw new ExecutionLimitException('jq: argument size limit exceeded');
                        }

                        if (! array_key_exists($name, $variables)) {
                            $variables[$name] = $value;
                        }
                    }
                } catch (JsonException|RuntimeException $exception) {
                    if ($exception instanceof ExecutionLimitException) {
                        throw $exception;
                    }

                    return $this->usageError('invalid JSON value for --argjson: '.$exception->getMessage(), 2);
                }

                $skip = $index + 3;

                continue;
            }

            $end = $end || $arg === '--';
            $remaining[] = $arg;
        }

        $parsed = $this->getopt($remaining, 'rcsne', [
            'raw-output' => ['r', false], 'compact-output' => ['c', false],
            'slurp' => ['s', false], 'null-input' => ['n', false], 'exit-status' => ['e', false],
        ], 2, true);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $operands] = $parsed;
        $filter = array_shift($operands) ?? '.';

        try {
            $node = new JqParser($filter, $commandContext->limits, $variables)->parse();
            $this->validateDepth($node, $commandContext->limits);
        } catch (JsonException|RuntimeException $exception) {
            if ($exception instanceof ExecutionLimitException) {
                throw $exception;
            }

            return $this->failure('jq: compile error: '.$exception->getMessage()."\n", 3);
        }

        $output = '';
        $stderr = '';
        $status = isset($flags['e']) ? 4 : 0;
        $jqEvaluator = new JqEvaluator($commandContext->limits);
        $inputs = $this->files($operands, $commandContext, $stderr);

        try {
            if (isset($flags['n'])) {
                $inputs = $this->single(null);
            } elseif (isset($flags['s'])) {
                $slurped = $jqEvaluator->collect($inputs);
                $inputs = $this->single($slurped);
            }

            foreach ($inputs as $input) {
                $jqEvaluator->weight($input);

                foreach ($jqEvaluator->evaluate($node, $input) as $value) {
                    $jqEvaluator->weight($value);
                    $chunks = isset($flags['r']) && is_string($value)
                        ? $this->raw($value)
                        : $this->render($value, isset($flags['c']));

                    foreach ($chunks as $chunk) {
                        $this->checkOutputSize($commandContext, strlen($output) + strlen($stderr) + strlen($chunk) + 1);
                        $output .= $chunk;
                    }

                    $output .= "\n";
                    $status = isset($flags['e']) && ($value === false || $value === null) ? 1 : 0;
                }

                // Like jq, a non-slurped input error stops after the next available value.
                if ($stderr !== '' && ! isset($flags['s'])) {
                    break;
                }
            }
        } catch (JsonException $exception) {
            return $this->failure($stderr.'jq: parse error: '.$exception->getMessage()."\n", 5, $output);
        } catch (RuntimeException $exception) {
            if ($exception instanceof ExecutionLimitException) {
                throw $exception;
            }

            return $this->failure($stderr.'jq: error: '.$exception->getMessage()."\n", 5, $output);
        }

        return new ExecResult(stdout: $output, stderr: $stderr, exitCode: $stderr === '' ? $status : 2);
    }

    /** @return Generator<int, mixed> */
    private function single(mixed $value): Generator
    {
        yield $value;
    }

    /** @return Generator<int, string> */
    private function raw(string $value): Generator
    {
        yield $value;
    }

    /**
     * @param  list<string>  $operands
     * @return Generator<int, mixed>
     */
    private function files(array $operands, CommandContext $commandContext, string &$stderr): Generator
    {
        foreach ($operands ?: ['-'] as $file) {
            try {
                $input = $this->readOperand($commandContext, $file);
            } catch (RuntimeException $exception) {
                if ($exception instanceof ExecutionLimitException) {
                    throw $exception;
                }

                $message = 'jq: '.$file.': '.$this->describeError($exception)."\n";
                $this->checkOutputSize($commandContext, strlen($stderr) + strlen($message));
                $stderr .= $message;

                continue;
            }

            yield from $this->inputs($input, $commandContext->limits);
        }
    }

    private function validateDepth(JqFilter $jqFilter, Limits $limits, int $depth = 0): void
    {
        if ($depth >= min(256, $limits->maxAstDepth)) {
            throw new ExecutionLimitException('jq: filter depth limit exceeded');
        }

        foreach ($jqFilter->children as $child) {
            $this->validateDepth($child, $limits, $depth + 1);
        }
    }

    /**
     * Split JSON values without requiring newline separators; decode each independently.
     *
     * @return Generator<int, mixed>
     */
    private function inputs(string $input, Limits $limits): Generator
    {
        if (strlen($input) > $limits->maxStringLength) {
            throw new ExecutionLimitException('jq: input size limit exceeded');
        }

        $offset = 0;
        $length = strlen($input);
        $tokens = 0;

        while ($offset < $length) {
            if (str_contains(" \t\r\n", $input[$offset])) {
                $offset++;

                continue;
            }

            $start = $offset;

            if (str_contains('{["', $input[$offset])) {
                $stack = [];
                $string = false;
                $escaped = false;

                do {
                    $char = $input[$offset++];

                    if ($string) {
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($char === '\\') {
                            $escaped = true;
                        } elseif ($char === '"') {
                            $string = false;
                        }
                    } elseif ($char === '"') {
                        $string = true;
                        $tokens++;
                    } elseif ($char === '[' || $char === '{') {
                        $stack[] = 0;
                        $tokens++;

                        if (count($stack) >= min(256, $limits->maxAstDepth)) {
                            throw new ExecutionLimitException('jq: JSON depth limit exceeded');
                        }
                    } elseif ($char === ']' || $char === '}') {
                        array_pop($stack);
                    } elseif ($char === ',') {
                        $tokens++;
                        $index = count($stack) - 1;

                        if ($index >= 0 && ++$stack[$index] >= $limits->maxArrayElements) {
                            throw new ExecutionLimitException('jq: array fanout limit exceeded');
                        }
                    }

                    if ($tokens > $limits->maxTokens) {
                        throw new ExecutionLimitException('jq: JSON token limit exceeded');
                    }
                } while ($offset < $length && ($stack !== [] || $string));
            } elseif (preg_match('/\G(?:true|false|null|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)(?=$|[\s\[\]{}",:])/A', $input, $match, 0, $offset) === 1) {
                $offset += strlen($match[0]);

                if (++$tokens > $limits->maxTokens) {
                    throw new ExecutionLimitException('jq: JSON token limit exceeded');
                }
            } else {
                throw new JsonException('invalid JSON at byte '.$offset);
            }

            $value = json_decode(substr($input, $start, $offset - $start), false, min(257, max(1, $limits->maxAstDepth + 1)), JSON_THROW_ON_ERROR);

            yield $value;
        }
    }

    /**
     * Emit bounded chunks, using jq's two-space indentation rather than PHP's four.
     *
     * @return Generator<int, string>
     */
    private function render(mixed $value, bool $compact, int $depth = 0): Generator
    {
        if (! is_array($value) && ! $value instanceof stdClass) {
            yield json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);

            return;
        }

        $object = $value instanceof stdClass;

        yield $object ? '{' : '[';
        $first = true;

        foreach ($object ? get_object_vars($value) : $value as $key => $item) {
            if (! $first) {
                yield ',';
            }

            if (! $compact) {
                yield "\n".str_repeat('  ', $depth + 1);
            }

            if ($object) {
                yield json_encode((string) $key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS).($compact ? ':' : ': ');
            }

            yield from $this->render($item, $compact, $depth + 1);
            $first = false;
        }

        if (! $first && ! $compact) {
            yield "\n".str_repeat('  ', $depth);
        }

        yield $object ? '}' : ']';
    }
}
