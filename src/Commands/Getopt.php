<?php

declare(strict_types=1);

namespace BashBox\Commands;

use InvalidArgumentException;

/** GNU getopt_long: bundled short options, `-oVALUE`/`-o VALUE`, long-option prefixes, `--name=value` and `--`. */
final class Getopt
{
    /**
     * @param  list<string>  $args
     * @param  string  $short  option letters, each followed by ':' when it takes an argument; a leading '+' stops at the first operand
     * @param  array<string, array{string, ?bool}>  $long  long name => [the option it stands for, takes an argument (null: optionally, after `=`)]
     * @param  bool  $numbers  whether a negative number is an operand rather than options, as in seq
     * @return array{list<array{string, string}>, list<string>} the options in order (with their argument, or ''), and the operands
     *
     * @throws InvalidArgumentException with getopt's message (without the program name)
     */
    public static function parse(array $args, string $short, array $long = [], bool $numbers = false): array
    {
        $inOrder = str_starts_with($short, '+');
        $short = ltrim($short, '+');
        $options = [];
        $operands = [];
        $counter = count($args);

        for ($i = 0; $i < $counter; $i++) {
            $arg = $args[$i];

            if ($arg === '--') {
                return [$options, [...$operands, ...array_slice($args, $i + 1)]];
            }

            if (str_starts_with($arg, '--')) {
                $name = explode('=', substr($arg, 2), 2)[0];
                $value = str_contains($arg, '=') ? substr($arg, strlen($name) + 3) : null;
                $matches = isset($long[$name]) ? [$name] : array_values(array_filter(array_keys($long), fn (string $candidate): bool => str_starts_with($candidate, $name)));

                if ($matches === []) {
                    throw new InvalidArgumentException(sprintf("unrecognized option '%s'", $arg));
                }

                if (count($matches) > 1) {
                    throw new InvalidArgumentException(sprintf("option '--%s' is ambiguous; possibilities:", $name).implode('', array_map(fn (string $m): string => sprintf(" '--%s'", $m), $matches)));
                }

                [$option, $takesValue] = $long[$matches[0]];

                if ($takesValue === false && $value !== null) {
                    throw new InvalidArgumentException(sprintf("option '--%s' doesn't allow an argument", $matches[0]));
                }

                if ($takesValue && $value === null) {
                    $value = $args[++$i] ?? throw new InvalidArgumentException(sprintf("option '--%s' requires an argument", $matches[0]));
                }

                $options[] = [$option, $value ?? ''];

                continue;
            }

            if ($arg === '-' || ! str_starts_with($arg, '-') || ($numbers && preg_match('/^-[\d.]/', $arg) === 1)) {
                if ($inOrder) {
                    return [$options, array_slice($args, $i)];
                }

                $operands[] = $arg;

                continue;
            }

            for ($j = 1, $len = strlen($arg); $j < $len; $j++) {
                $letter = $arg[$j];
                $at = $letter === ':' ? false : strpos($short, $letter);

                if ($at === false) {
                    throw new InvalidArgumentException(sprintf("invalid option -- '%s'", $letter));
                }

                if (($short[$at + 1] ?? '') !== ':') {
                    $options[] = [$letter, ''];

                    continue;
                }

                $options[] = [$letter, $j + 1 < $len ? substr($arg, $j + 1) : ($args[++$i] ?? throw new InvalidArgumentException(sprintf("option requires an argument -- '%s'", $letter)))];

                break;
            }
        }

        return [$options, $operands];
    }
}
