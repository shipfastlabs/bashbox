<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Uniq_ extends AbstractCommand
{
    private const array LONG = [
        'count' => ['c', false],
        'repeated' => ['d', false],
        'skip-fields' => ['f', true],
        'ignore-case' => ['i', false],
        'skip-chars' => ['s', true],
        'unique' => ['u', false],
        'check-chars' => ['w', true],
    ];

    public function getName(): string
    {
        return 'uniq';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->getopt($args, 'cdf:is:uw:', self::LONG);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $operands] = $parsed;

        if (isset($operands[2])) {
            return $this->usageError(sprintf("extra operand '%s'", $operands[2]));
        }

        foreach (['f' => 'fields to skip', 's' => 'bytes to skip', 'w' => 'bytes to compare'] as $option => $what) {
            if (isset($flags[$option]) && ! ctype_digit($flags[$option])) {
                return $this->failure(sprintf("uniq: %s: invalid number of %s\n", $flags[$option], $what));
            }
        }

        [$contents, $stderr] = $this->readFiles($commandContext, [$operands[0] ?? '-'], "uniq: %s: %s\n");

        if ($stderr !== '') {
            return $this->failure($stderr);
        }

        /** @var list<array{line: string, key: string, count: int}> $groups */
        $groups = [];

        foreach ($this->splitLines($contents[0])['lines'] as $line) {
            $key = $this->key($line, (int) ($flags['f'] ?? 0), (int) ($flags['s'] ?? 0), isset($flags['w']) ? (int) $flags['w'] : null, isset($flags['i']));

            if ($groups !== [] && $groups[count($groups) - 1]['key'] === $key) {
                $groups[count($groups) - 1]['count']++;
            } else {
                $groups[] = ['line' => $line, 'key' => $key, 'count' => 1];
            }
        }

        $output = '';

        foreach ($groups as $group) {
            if (isset($flags['d']) && $group['count'] < 2) {
                continue;
            }

            if (isset($flags['u']) && $group['count'] > 1) {
                continue;
            }

            $output .= (isset($flags['c']) ? sprintf('%7d ', $group['count']) : '').$group['line']."\n";
        }

        if (isset($operands[1])) {
            try {
                $this->writeOutputFile($commandContext, $operands[1], $output);
            } catch (RuntimeException $runtimeException) {
                return $this->failure(sprintf("uniq: %s: %s\n", $operands[1], $this->describeError($runtimeException)));
            }

            return $this->success();
        }

        return $this->success($output);
    }

    /** The part of a line that is compared: after skipping fields (blanks, then non-blanks) and bytes, up to the width. */
    private function key(string $line, int $fields, int $chars, ?int $width, bool $ignoreCase): string
    {
        for ($pos = 0; $fields-- > 0 && $pos < strlen($line);) {
            $pos += strspn($line, " \t", $pos);
            $pos += strcspn($line, " \t", $pos);
        }

        $key = substr($line, $pos + $chars, $width);

        return $ignoreCase ? strtolower($key) : $key;
    }
}
