<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Wc extends AbstractCommand
{
    public function getName(): string
    {
        return 'wc';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->getopt($args, 'clmw', ['bytes' => ['c', false], 'chars' => ['m', false], 'lines' => ['l', false], 'words' => ['w', false]]);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $operands] = $parsed;
        // Columns come out in this order whatever order the flags were given in.
        $columns = array_values(array_filter(['l', 'w', 'm', 'c'], fn (string $column): bool => isset($flags[$column]))) ?: ['l', 'w', 'c'];

        [$contents, $stderr] = $this->readFiles($commandContext, $operands, "wc: %s: %s\n");
        $rows = [];
        $totals = ['l' => 0, 'w' => 0, 'm' => 0, 'c' => 0];

        foreach ($contents as $i => $content) {
            $counts = [
                'l' => substr_count($content, "\n"),
                'w' => count(preg_split('/\s+/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: []),
                // Characters are bytes in the C locale.
                'm' => strlen($content),
                'c' => strlen($content),
            ];
            $rows[] = [$counts, $operands === [] ? '' : ' '.$operands[$i]];

            foreach ($counts as $column => $count) {
                $totals[$column] += $count;
            }
        }

        if (count($operands) > 1) {
            $rows[] = [$totals, ' total'];
        }

        // GNU sizes columns to fit the total byte count; stdin's size is unknown, so it reserves 7
        $width = count($columns) === 1 && count($operands) <= 1
            ? 1
            : max(in_array('-', $operands ?: ['-'], true) ? 7 : 1, strlen((string) $totals['c']));

        $output = '';

        foreach ($rows as [$counts, $label]) {
            $output .= implode(' ', array_map(fn (string $column): string => sprintf('%*d', $width, $counts[$column]), $columns)).$label."\n";
        }

        return $stderr === '' ? $this->success($output) : $this->failure($stderr, 1, $output);
    }
}
