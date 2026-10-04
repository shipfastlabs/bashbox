<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Tr extends AbstractCommand
{
    private const array LONG = [
        'complement' => ['c', false],
        'delete' => ['d', false],
        'squeeze-repeats' => ['s', false],
        'truncate-set1' => ['t', false],
    ];

    public function getName(): string
    {
        return 'tr';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->getopt($args, 'cCdst', self::LONG);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $operands] = $parsed;
        $delete = isset($flags['d']);
        $squeeze = isset($flags['s']);
        $input = $commandContext->stdin;

        if ($operands === []) {
            return $this->usageError('missing operand');
        }

        if (! $delete && ! $squeeze && count($operands) < 2) {
            return $this->usageError(sprintf("missing operand after '%s'\nTwo strings must be given when translating.", $operands[0]));
        }

        $deleteOnly = $delete && ! $squeeze;

        if (isset($operands[$deleteOnly ? 1 : 2])) {
            return $this->usageError(sprintf("extra operand '%s'", $operands[$deleteOnly ? 1 : 2]).($deleteOnly ? "\nOnly one string may be given when deleting without squeezing repeats." : ''));
        }

        try {
            $set1 = $this->expandSet($operands[0]);
            $set2 = isset($operands[1]) ? $this->expandSet($operands[1]) : null;
        } catch (RuntimeException $runtimeException) {
            return $this->failure('tr: '.$runtimeException->getMessage()."\n");
        }

        if (isset($flags['c']) || isset($flags['C'])) {
            // The complement is in byte order, as in the C locale.
            $set1 = implode('', array_diff(array_map(chr(...), range(0, 255)), str_split($set1)));
        }

        if (isset($flags['t']) && $set2 !== null) {
            $set1 = substr($set1, 0, strlen($set2));
        }

        if ($delete) {
            $output = $this->deleteChars($input, $set1);

            return $this->success($squeeze && $set2 !== null ? $this->squeezeChars($output, $set2) : $output);
        }

        if ($set2 === null) {
            return $this->success($this->squeezeChars($input, $set1));
        }

        if ($set2 === '') {
            return $this->failure("tr: when not truncating set1, string2 must be non-empty\n");
        }

        $output = $this->translateChars($input, $set1, $set2);

        return $this->success($squeeze ? $this->squeezeChars($output, $set2) : $output);
    }

    private function expandSet(string $spec): string
    {
        $result = '';
        $len = strlen($spec);

        for ($i = 0; $i < $len; $i++) {
            // Handle escape sequences
            if ($spec[$i] === '\\' && $i + 1 < $len) {
                if (preg_match('/[0-7]{1,3}/A', $spec, $octal, 0, $i + 1) === 1) {
                    $result .= chr(octdec($octal[0]) & 0xFF);
                    $i += strlen($octal[0]);

                    continue;
                }

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

                if ($start > $end) {
                    throw new RuntimeException(sprintf("range-endpoints of '%s' are in reverse collating sequence order", substr($spec, $i, 3)));
                }

                $result .= implode('', array_map(chr(...), range($start, $end)));

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

                    continue;
                }
            }

            $result .= $spec[$i];
        }

        return $result;
    }

    private function expandClass(string $class): string
    {
        $test = match ($class) {
            'alnum' => ctype_alnum(...),
            'alpha' => ctype_alpha(...),
            'blank' => fn (string $c): bool => $c === ' ' || $c === "\t",
            'cntrl' => ctype_cntrl(...),
            'digit' => ctype_digit(...),
            'graph' => ctype_graph(...),
            'lower' => ctype_lower(...),
            'print' => ctype_print(...),
            'punct' => ctype_punct(...),
            'space' => ctype_space(...),
            'upper' => ctype_upper(...),
            'xdigit' => ctype_xdigit(...),
            default => throw new RuntimeException(sprintf("invalid character class '%s'", $class)),
        };

        // Members in byte order, as in the C locale
        return implode('', array_filter(array_map(chr(...), range(0, 255)), $test));
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
