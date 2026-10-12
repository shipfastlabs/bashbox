<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\Exceptions\ExecutionLimitException;
use BashBox\ExecResult;
use BashBox\Regex\RegexException;
use BashBox\Regex\SafePcreRegex;
use InvalidArgumentException;
use RuntimeException;

/** A bounded, non-interactive ripgrep subset; no ignore files or Rust regex emulation. */
final class Rg_ extends AbstractCommand
{
    private const array LONG = [
        'regexp' => ['e', true], 'fixed-strings' => ['F', false],
        'ignore-case' => ['i', false], 'invert-match' => ['v', false],
        'word-regexp' => ['w', false], 'line-regexp' => ['x', false],
        'line-number' => ['n', false], 'no-line-number' => ['N', false],
        'with-filename' => ['H', false], 'no-filename' => ['h', false],
        'files-with-matches' => ['l', false], 'count' => ['c', false],
        'quiet' => ['q', false], 'only-matching' => ['o', false],
        'files' => ['files', false], 'hidden' => ['hidden', false],
        'text' => ['a', false], 'glob' => ['g', true],
        'after-context' => ['A', true], 'before-context' => ['B', true],
        'context' => ['C', true],
    ];

    public function getName(): string
    {
        return 'rg';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        try {
            [$options, $operands] = Getopt::parse($args, 'e:FiwvxnNHlcqoag:A:B:C:', self::LONG);
            $flags = [];
            $patterns = [];
            $globs = [];
            $before = 0;
            $after = 0;

            foreach ($options as [$option, $value]) {
                if ($option === 'e') {
                    $patterns[] = $value;
                } elseif ($option === 'g') {
                    $globs[] = $this->glob($value);
                } elseif (in_array($option, ['A', 'B', 'C'], true)) {
                    if (! ctype_digit($value) || strlen($value) > 9) {
                        throw new InvalidArgumentException('context must be a non-negative integer');
                    }

                    if ($option !== 'A') {
                        $before = (int) $value;
                    }

                    if ($option !== 'B') {
                        $after = (int) $value;
                    }
                } else {
                    $flags[$option] = true;
                    unset($flags[match ($option) {
                        'n' => 'N', 'N' => 'n', 'H' => 'h', 'h' => 'H',
                        default => '',
                    }]);
                }
            }

            if (! isset($flags['files']) && $patterns === []) {
                $patterns[] = array_shift($operands) ?? throw new InvalidArgumentException('a pattern is required');
            }

            $regex = $this->regex($patterns, $flags);

            if (! isset($flags['files'])) {
                // Validate even if there are no files or lines, without leaking a host warning.
                set_error_handler(static fn (): bool => true, E_WARNING);

                try {
                    SafePcreRegex::match($regex, '');
                } finally {
                    restore_error_handler();
                }
            }

            // CommandContext exposes bytes, not whether a pipe/redirection was attached.
            // Empty stdin therefore defaults to traversal; an explicit '-' is unambiguous.
            if ($operands === []) {
                $operands = ! isset($flags['files']) && $commandContext->stdin !== '' ? ['-'] : ['.'];
                $defaultDirectory = $operands === ['.'];
            } else {
                $defaultDirectory = false;
            }

            $output = '';
            $stderr = '';
            $found = false;
            $operations = 0;
            $withName = ! isset($flags['h']) && (isset($flags['H']) || count($operands) > 1);

            foreach ($operands as $operand) {
                $stack = [[$operand, $defaultDirectory ? '' : $operand, false]];

                while ($stack !== []) {
                    [$file, $label, $recursed] = array_pop($stack);

                    if (++$operations > $commandContext->limits->maxGlobOperations) {
                        throw new ExecutionLimitException('rg: traversal limit exceeded');
                    }

                    try {
                        $path = $this->resolvePath($commandContext, $file);

                        if ($file !== '-' && $commandContext->fs->stat($path)->isDirectory) {
                            $entries = $commandContext->fs->readdirWithFileTypes($path);

                            if (count($entries) + $operations > $commandContext->limits->maxGlobOperations) {
                                throw new ExecutionLimitException('rg: traversal limit exceeded');
                            }

                            $operations += count($entries);

                            foreach (array_reverse($entries) as $entry) {
                                if ($entry->isSymbolicLink || in_array($entry->name, ['.', '..'], true)
                                    || (! isset($flags['hidden']) && str_starts_with($entry->name, '.'))) {
                                    continue;
                                }

                                $stack[] = [rtrim($path, '/').'/'.$entry->name, ($label === '' ? '' : rtrim($label, '/').'/').$entry->name, true];
                            }

                            continue;
                        }

                        if ($recursed && ! $this->included($label, $globs)) {
                            continue;
                        }

                        $label = $file === '-' ? '<stdin>' : $label;

                        if (isset($flags['files'])) {
                            $this->append($commandContext, $output, $label."\n", strlen($stderr));
                            $found = true;

                            continue;
                        }

                        $content = $this->readOperand($commandContext, $file);

                        if (strlen($content) > $commandContext->limits->maxStringLength) {
                            throw new ExecutionLimitException('rg: input size limit exceeded');
                        }

                        if (! isset($flags['a']) && str_contains($content, "\0")) {
                            continue;
                        }

                        [$chunk, $matched] = $this->search($commandContext, $content, $label, $regex, $flags,
                            $withName || ($recursed && ! isset($flags['h'])), $before, $after);
                        $found = $found || $matched;
                        $this->append($commandContext, $output, $chunk, strlen($stderr));

                        if ($matched && isset($flags['q'])) {
                            return new ExecResult(stdout: '', stderr: $stderr, exitCode: $stderr === '' ? 0 : 2);
                        }
                    } catch (ExecutionLimitException $exception) {
                        throw $exception;
                    } catch (RuntimeException $exception) {
                        $this->append($commandContext, $stderr, 'rg: '.$label.': '.$this->describeError($exception)."\n", strlen($output));
                    }
                }
            }

            return new ExecResult(stdout: $output, stderr: $stderr, exitCode: $stderr !== '' ? 2 : ($found ? 0 : 1));
        } catch (InvalidArgumentException|RegexException $exception) {
            return $this->failure('rg: '.$exception->getMessage()."\n", 2);
        }
    }

    /** @param list<string> $patterns
     * @param  array<string, bool>  $flags
     */
    private function regex(array $patterns, array $flags): string
    {
        $parts = [];

        foreach ($patterns as $pattern) {
            if (str_contains($pattern, "\n")) {
                throw new InvalidArgumentException('multiline patterns are unsupported');
            }

            // A reserved delimiter avoids corrupting already-escaped punctuation.
            if (str_contains($pattern, "\x01")) {
                throw new InvalidArgumentException('patterns containing byte 0x01 are unsupported');
            }

            $part = isset($flags['F']) ? preg_quote($pattern, "\x01") : $pattern;
            $part = '(?:'.$part.')';

            if (isset($flags['x'])) {
                $part = '^'.$part.'$';
            } elseif (isset($flags['w'])) {
                $part = '(?<!\w)'.$part.'(?!\w)';
            }

            $parts[] = $part;
        }

        return "\x01".implode('|', $parts)."\x01".(isset($flags['i']) ? 'i' : '');
    }

    /** @return array{string, bool} */
    private function glob(string $glob): array
    {
        // ponytail: braces and character classes are rejected, not approximated.
        if (strpbrk($glob, '{}[]\\') !== false || $glob === '' || $glob === '!') {
            throw new InvalidArgumentException('unsupported or empty glob');
        }

        $exclude = str_starts_with($glob, '!');
        $pattern = $exclude ? substr($glob, 1) : $glob;
        $regex = str_starts_with($pattern, '/') ? '^' : '(?:^|/)';
        $pattern = ltrim($pattern, '/');

        for ($i = 0, $length = strlen($pattern); $i < $length; $i++) {
            $componentStart = $i === 0 || $pattern[$i - 1] === '/';

            if ($componentStart && substr($pattern, $i, 3) === '**/') {
                $regex .= '(?:.*/)?';
                $i += 2;
            } elseif ($componentStart && substr($pattern, $i, 2) === '**' && $i + 2 === $length) {
                $regex .= '.*';
                $i++;
            } else {
                $regex .= match ($pattern[$i]) {
                    '*' => '[^/]*', '?' => '[^/]',
                    default => preg_quote($pattern[$i], '~'),
                };
            }
        }

        return ['~'.$regex.($exclude ? '(?:/|$)' : '$').'~', $exclude];
    }

    /** @param list<array{string, bool}> $globs */
    private function included(string $label, array $globs): bool
    {
        $included = count(array_filter($globs, fn (array $glob): bool => ! $glob[1])) === 0;

        foreach ($globs as [$regex, $exclude]) {
            if (SafePcreRegex::match($regex, str_starts_with($label, './') ? substr($label, 2) : $label)) {
                $included = ! $exclude;
            }
        }

        return $included;
    }

    /** @param array<string, bool> $flags
     * @return array{string, bool}
     */
    private function search(CommandContext $commandContext, string $content, string $label, string $regex, array $flags, bool $withName, int $before, int $after): array
    {
        $lineCount = substr_count($content, "\n") + (int) ($content !== '' && ! str_ends_with($content, "\n"));

        if ($lineCount > $commandContext->limits->maxArrayElements) {
            throw new ExecutionLimitException('rg: line count limit exceeded');
        }

        $lines = $this->splitLines($content)['lines'];
        $selected = [];
        $count = 0;

        // With inversion, ripgrep's -o emits the selected non-matching lines.
        if (isset($flags['v'])) {
            unset($flags['o']);
        }

        foreach ($lines as $index => $line) {
            if (@SafePcreRegex::match($regex, $line) !== isset($flags['v'])) {
                $selected[$index] = true;
                $count += isset($flags['c'], $flags['o'])
                    ? count(@SafePcreRegex::matchAll($regex, $line, $commandContext->limits->maxArrayElements))
                    : 1;

                if (isset($flags['q']) || isset($flags['l'])) {
                    return [isset($flags['q']) ? '' : $label."\n", true];
                }
            }
        }

        if (isset($flags['c'])) {
            return [$selected === [] ? '' : ($withName ? $label.':' : '').$count."\n", $selected !== []];
        }

        $output = '';
        $last = -2;
        $end = -1;
        $numbers = isset($flags['n']) && ! isset($flags['N']);

        foreach (array_keys($selected) as $hit) {
            $start = max(0, $last + 1, $hit - $before);
            $end = max($end, min(count($lines) - 1, $hit + $after));

            if ($start > $last + 1 && $last >= 0 && ($before > 0 || $after > 0)) {
                $this->append($commandContext, $output, "--\n");
            }

            for ($index = $start; $index <= $end; $index++) {
                $separator = isset($selected[$index]) ? ':' : '-';
                $prefix = ($withName ? $label.$separator : '').($numbers ? ($index + 1).$separator : '');
                $pieces = isset($flags['o'], $selected[$index])
                    ? @SafePcreRegex::matchAll($regex, $lines[$index], $commandContext->limits->maxArrayElements)
                    : [$lines[$index]];

                foreach ($pieces as $piece) {
                    $this->append($commandContext, $output, $prefix.$piece."\n");
                }

                $last = $index;
            }
        }

        return [$output, $selected !== []];
    }

    private function append(CommandContext $commandContext, string &$output, string $chunk, int $otherSize = 0): void
    {
        $this->checkOutputSize($commandContext, strlen($output) + strlen($chunk) + $otherSize);
        $output .= $chunk;
    }
}
