<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use InvalidArgumentException;

/** `expand`; `unexpand` shares the tab stops and file handling. Like GNU, the files are read as one stream. */
class Expand extends AbstractCommand
{
    /** @var list<int> */
    protected array $stops = [];

    /** Tab size after the last stop, from `/N` (`$extend`) or `+N` (`$increment`). */
    protected int $extend = 0;

    protected int $increment = 0;

    /** Distance between stops when there is a single one. */
    protected int $size = 0;

    public function getName(): string
    {
        return 'expand';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        // Obsolete `-N[,M...]` for `-t N[,M...]`.
        foreach ($args as $i => $arg) {
            if (preg_match('/^-(i*)(\d.*)$/s', $arg, $m) === 1 && preg_match('/^-i*t$|^--t[a-z]*$/', $args[$i - 1] ?? '') !== 1) {
                $args[$i] = $m[1] === '' ? '--tabs='.$m[2] : '-'.$m[1].'t'.$m[2];
            }
        }

        try {
            [$options, $files] = Getopt::parse($args, 'it:', ['initial' => ['i', false], 'tabs' => ['t', true]]);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->usageError($invalidArgumentException->getMessage());
        }

        $this->resetStops();
        $all = true;

        foreach ($options as [$option, $value]) {
            if ($option === 'i') {
                $all = false;
            } elseif (($error = $this->parseStops($value)) !== null) {
                return $this->failure("expand: {$error}\n");
            }
        }

        return $this->run($commandContext, $files, fn (string $input): string => $this->expand($input, $all));
    }

    /**
     * Reads the files as one stream and converts it once the tab stops are final.
     *
     * @param  list<string>  $files
     * @param  callable(string): string  $convert
     */
    protected function run(CommandContext $commandContext, array $files, callable $convert): ExecResult
    {
        $error = $this->finalizeStops();

        if ($error !== null) {
            return $this->failure(sprintf("%s: %s\n", $this->getName(), $error));
        }

        [$contents, $stderr] = $this->readFiles($commandContext, $files, $this->getName().": %s: %s\n");
        $output = $convert(implode('', $contents));

        return $stderr === '' ? $this->success($output) : $this->failure($stderr, 1, $output);
    }

    protected function resetStops(): void
    {
        $this->stops = [];
        $this->extend = 0;
        $this->increment = 0;
    }

    /** Adds a -t list's stops, returning GNU's complaint about it if any. */
    protected function parseStops(string $list): ?string
    {
        $value = null;
        $start = 0;
        // `/` and `+` stay in force after a comma, so only the last value may carry them.
        $prefix = '';

        for ($i = 0, $len = strlen($list); $i <= $len; $i++) {
            $char = $list[$i] ?? ',';

            if (str_contains(", \t", $char)) {
                $error = $value === null ? null : $this->addStop($value, $prefix);
                $value = null;

                if ($error !== null) {
                    return $error;
                }
            } elseif ($char === '/' || $char === '+') {
                if ($value !== null) {
                    return sprintf("'%s' specifier not at start of number: '%s'", $char, substr($list, $i));
                }

                $prefix = $char;
            } elseif (ctype_digit($char)) {
                $start = $value === null ? $i : $start;

                if (($value ?? 0) > intdiv(PHP_INT_MAX - (int) $char, 10)) {
                    return sprintf("tab stop is too large '%s'", substr($list, $start, strspn($list, '0123456789', $start)));
                }

                $value = ($value ?? 0) * 10 + (int) $char;
            } else {
                return sprintf("tab size contains invalid character(s): '%s'", substr($list, $i));
            }
        }

        return null;
    }

    private function addStop(int $value, string $prefix): ?string
    {
        if ($prefix === '') {
            $this->stops[] = $value;
        } elseif (($prefix === '/' ? $this->extend : $this->increment) !== 0) {
            return sprintf("'%s' specifier only allowed with the last value", $prefix);
        } elseif ($prefix === '/') {
            $this->extend = $value;
        } else {
            $this->increment = $value;
        }

        return null;
    }

    /** Validates the stops and picks the single tab size, if there is one. */
    protected function finalizeStops(): ?string
    {
        foreach ($this->stops as $i => $stop) {
            if ($stop === 0) {
                return 'tab size cannot be 0';
            }

            if ($stop <= ($this->stops[$i - 1] ?? 0)) {
                return 'tab sizes must be ascending';
            }
        }

        if ($this->extend !== 0 && $this->increment !== 0) {
            return "'/' specifier is mutually exclusive with '+'";
        }

        $this->size = match (true) {
            $this->stops === [] => $this->extend ?: $this->increment ?: 8,
            count($this->stops) === 1 && $this->extend === 0 && $this->increment === 0 => $this->stops[0],
            default => 0,
        };

        return null;
    }

    /**
     * The column of the next tab stop after $column, and whether it is only the next column because the stops ran out.
     *
     * @return array{int, bool}
     */
    protected function nextStop(int $column, int &$index): array
    {
        if ($this->size !== 0) {
            return [$column + $this->size - $column % $this->size, false];
        }

        $counter = count($this->stops);

        for (; $index < $counter; $index++) {
            if ($column < $this->stops[$index]) {
                return [$this->stops[$index], false];
            }
        }

        return match (true) {
            $this->extend !== 0 => [$column + $this->extend - $column % $this->extend, false],
            $this->increment !== 0 => [$column + $this->increment - ($column - end($this->stops)) % $this->increment, false],
            default => [$column + 1, true],
        };
    }

    private function expand(string $input, bool $all): string
    {
        $output = '';
        $column = 0;
        $index = 0;
        $convert = true;

        foreach (str_split($input) as $char) {
            if ($convert) {
                if ($char === "\t") {
                    [$next] = $this->nextStop($column, $index);
                    $output .= str_repeat(' ', $next - $column - 1);
                    $column = $next;
                    $char = ' ';
                } elseif ($char === "\x08") {
                    $column = max(0, $column - 1);
                    $index = max(0, $index - 1);
                } else {
                    $column++;
                }

                $convert = $all || $char === ' ';
            }

            $output .= $char;

            if ($char === "\n") {
                [$column, $index, $convert] = [0, 0, true];
            }
        }

        return $output;
    }
}
