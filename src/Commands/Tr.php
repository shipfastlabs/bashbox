<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Tr extends AbstractCommand
{
    public function getName(): string
    {
        return 'tr';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->parseFlags($args, [
            'd' => false,
            's' => false,
        ]);

        $flags = $parsed['flags'];
        $remaining = $parsed['args'];

        $input = $commandContext->stdin;

        if ($flags['d']) {
            // Delete mode: tr -d SET1
            if ($remaining === []) {
                return $this->failure("tr: missing operand\n");
            }

            $set1 = $this->expandSet($remaining[0]);
            $output = $this->deleteChars($input, $set1);

            if ($flags['s'] && isset($remaining[1])) {
                $set2 = $this->expandSet($remaining[1]);
                $output = $this->squeezeChars($output, $set2);
            }

            return $this->success($output);
        }

        if ($flags['s'] && count($remaining) === 1) {
            // Squeeze only: tr -s SET1
            $set1 = $this->expandSet($remaining[0]);
            $output = $this->squeezeChars($input, $set1);

            return $this->success($output);
        }

        if (count($remaining) < 2) {
            return $this->failure("tr: missing operand\n");
        }

        $set1 = $this->expandSet($remaining[0]);
        $set2 = $this->expandSet($remaining[1]);

        // Translate mode
        $output = $this->translateChars($input, $set1, $set2);

        if ($flags['s']) {
            $output = $this->squeezeChars($output, $set2);
        }

        return $this->success($output);
    }

    private function expandSet(string $spec): string
    {
        $result = '';
        $len = strlen($spec);

        for ($i = 0; $i < $len; $i++) {
            // Handle escape sequences
            if ($spec[$i] === '\\' && $i + 1 < $len) {
                $next = $spec[$i + 1];
                $result .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    'a' => "\x07",
                    'b' => "\x08",
                    'f' => "\x0C",
                    'v' => "\x0B",
                    '\\' => '\\',
                    default => $next,
                };
                $i++;

                continue;
            }

            // Handle character ranges: a-z
            if ($i + 2 < $len && $spec[$i + 1] === '-') {
                $start = ord($spec[$i]);
                $end = ord($spec[$i + 2]);

                if ($start <= $end) {
                    for ($c = $start; $c <= $end; $c++) {
                        $result .= chr($c);
                    }
                } else {
                    for ($c = $start; $c >= $end; $c--) {
                        $result .= chr($c);
                    }
                }

                $i += 2;

                continue;
            }

            // Handle character classes
            if ($spec[$i] === '[' && $i + 2 < $len && $spec[$i + 1] === ':') {
                $end = strpos($spec, ':]', $i + 2);

                if ($end !== false) {
                    $class = substr($spec, $i + 2, $end - $i - 2);
                    $result .= $this->expandClass($class);
                    $i = $end + 1;

                    // skip the closing ]
                    if ($i + 1 < $len && $spec[$i + 1] === ']') {
                        $i++;
                    }

                    continue;
                }
            }

            $result .= $spec[$i];
        }

        return $result;
    }

    private function expandClass(string $class): string
    {
        return match ($class) {
            'upper' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'lower' => 'abcdefghijklmnopqrstuvwxyz',
            'alpha' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz',
            'digit' => '0123456789',
            'alnum' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789',
            'space' => " \t\n\r\x0B\x0C",
            'blank' => " \t",
            default => '',
        };
    }

    private function deleteChars(string $input, string $set): string
    {
        $chars = str_split($set);
        $output = '';

        for ($i = 0; $i < strlen($input); $i++) {
            if (! in_array($input[$i], $chars, true)) {
                $output .= $input[$i];
            }
        }

        return $output;
    }

    private function squeezeChars(string $input, string $set): string
    {
        $chars = str_split($set);
        $output = '';
        $prevChar = null;

        for ($i = 0; $i < strlen($input); $i++) {
            $ch = $input[$i];

            if ($ch === $prevChar && in_array($ch, $chars, true)) {
                continue;
            }

            $output .= $ch;
            $prevChar = $ch;
        }

        return $output;
    }

    private function translateChars(string $input, string $set1, string $set2): string
    {
        if ($set2 === '') {
            return $input;
        }

        // Build translation map
        $map = [];
        $len1 = strlen($set1);
        $len2 = strlen($set2);

        for ($i = 0; $i < $len1; $i++) {
            // If set2 is shorter, use its last character for remaining set1 chars
            $replaceIdx = min($i, $len2 - 1);
            $map[$set1[$i]] = $set2[$replaceIdx];
        }

        $output = '';

        for ($i = 0; $i < strlen($input); $i++) {
            $ch = $input[$i];
            $output .= $map[$ch] ?? $ch;
        }

        return $output;
    }
}
