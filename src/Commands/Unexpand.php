<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use InvalidArgumentException;
use Override;

final class Unexpand extends Expand
{
    private const array LONG = [
        'all' => ['a', false],
        'first-only' => ['f', false],
        'tabs' => ['t', true],
    ];

    #[Override]
    public function getName(): string
    {
        return 'unexpand';
    }

    #[Override]
    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        try {
            // Digits and commas are options too: obsolete `-N,M` for `-t N,M`.
            [$options, $files] = Getopt::parse($args, ',0123456789at:', self::LONG);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->usageError($invalidArgumentException->getMessage());
        }

        $this->resetStops();
        $all = false;
        $firstOnly = false;
        $value = null;

        foreach ($options as [$option, $argument]) {
            if (ctype_digit($option)) {
                $value = ($value ?? 0) * 10 + (int) $option;
            } elseif ($option === ',') {
                $this->stops = $value === null ? $this->stops : [...$this->stops, $value];
                $value = null;
            } elseif ($option === 'f') {
                $firstOnly = true;
            } else {
                $all = true;

                if ($option === 't' && ($error = $this->parseStops($argument)) !== null) {
                    return $this->failure("unexpand: {$error}\n");
                }
            }
        }

        $this->stops = $value === null ? $this->stops : [...$this->stops, $value];

        return $this->run($commandContext, $files, fn (string $input): string => $this->unexpand($input, $all && ! $firstOnly));
    }

    /** GNU's algorithm: blanks are held back until it is known whether they reach a tab stop. */
    private function unexpand(string $input, bool $all): string
    {
        $output = '';
        $state = $this->lineStart();

        foreach ([...str_split($input), null] as $char) {
            [$convert, $column, $index, $oneBlank, $previousBlank, $pending] = $state;

            if ($convert) {
                $blank = $char === ' ' || $char === "\t";

                [$next, $last] = $blank ? $this->nextStop($column, $index) : [0, false];
                $convert = ! $last;

                if ($blank && $convert) {
                    if ($char === "\t") {
                        $column = $next;
                    } elseif (++$column !== $next || ! $previousBlank) {
                        // Not yet known whether these blanks will become a tab.
                        $state = [true, $column, $index, $oneBlank || $column === $next, true, $pending.$char];

                        continue;
                    } else {
                        $char = "\t";
                    }

                    // A single blank just before the previous stop becomes a tab of its own.
                    $pending = $oneBlank ? "\t" : '';
                } elseif ($char === "\x08") {
                    $column = max(0, $column - 1);
                    $index = max(0, $index - 1);
                } elseif (! $blank) {
                    $column++;
                }

                if ($pending !== '') {
                    $output .= strlen($pending) > 1 && $oneBlank ? "\t".substr($pending, 1) : $pending;
                    $pending = '';
                    $oneBlank = false;
                }

                $previousBlank = $blank;
                $convert = $convert && ($all || $blank);
            }

            $output .= $char;
            $state = $char === "\n" ? $this->lineStart() : [$convert, $column, $index, $oneBlank, $previousBlank, $pending];
        }

        return $output;
    }

    /** @return array{bool, int, int, bool, bool, string} */
    private function lineStart(): array
    {
        return [true, 0, 0, false, true, ''];
    }
}
