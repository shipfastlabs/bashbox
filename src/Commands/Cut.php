<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Cut extends AbstractCommand
{
    private const array LONG = [
        'bytes' => ['b', true],
        'characters' => ['c', true],
        'delimiter' => ['d', true],
        'fields' => ['f', true],
        'only-delimited' => ['s', false],
        'complement' => ['complement', false],
    ];

    public function getName(): string
    {
        return 'cut';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->getopt($args, 'b:c:d:f:s', self::LONG);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $operands] = $parsed;
        $complement = isset($flags['complement']);
        // Like GNU cut, -c is byte-based.
        $lists = array_intersect_key($flags, ['b' => 0, 'c' => 0, 'f' => 0]);

        if (count($lists) !== 1) {
            return $this->usageError($lists === [] ? 'you must specify a list of bytes, characters, or fields' : 'only one list may be specified');
        }

        $ranges = $this->parseRanges(reset($lists));
        $byField = isset($flags['f']);
        // GNU takes an empty delimiter as NUL.
        $delimiter = isset($flags['d']) ? ($flags['d'] === '' ? "\0" : $flags['d']) : "\t";

        if (strlen($delimiter) !== 1) {
            return $this->usageError('the delimiter must be a single character');
        }

        [$contents, $stderr] = $this->readFiles($commandContext, $operands, "cut: %s: %s\n");
        $output = '';

        foreach ($this->splitLines(implode('', $contents))['lines'] as $line) {
            if ($byField && ! str_contains($line, $delimiter)) {
                $output .= isset($flags['s']) ? '' : $line."\n";

                continue;
            }

            $units = $byField ? explode($delimiter, $line) : str_split($line);
            $kept = array_filter($units, fn (int $i): bool => $this->inRanges($i + 1, $ranges) !== $complement, ARRAY_FILTER_USE_KEY);
            $output .= implode($byField ? $delimiter : '', $kept)."\n";
        }

        return $stderr === '' ? $this->success($output) : $this->failure($stderr, 1, $output);
    }

    /**
     * Parse a list like "1,3", "1-3", "2-", "-4" into inclusive [start, end] ranges.
     *
     * @return list<array{int, int}>
     */
    private function parseRanges(string $spec): array
    {
        return array_map(function (string $part): array {
            [$start, $end] = str_contains($part, '-') ? explode('-', $part, 2) : [$part, $part];

            return [$start === '' ? 1 : (int) $start, $end === '' ? PHP_INT_MAX : (int) $end];
        }, explode(',', $spec));
    }

    /**
     * @param  list<array{int, int}>  $ranges
     */
    private function inRanges(int $position, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($position >= $start && $position <= $end) {
                return true;
            }
        }

        return false;
    }
}
