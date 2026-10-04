<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use InvalidArgumentException;
use RuntimeException;

final class Comm extends AbstractCommand
{
    private const array LONG = [
        'check-order' => ['c', false],
        'nocheck-order' => ['C', false],
        'output-delimiter' => ['o', true],
        'total' => ['t', false],
        'zero-terminated' => ['z', false],
    ];

    public function getName(): string
    {
        return 'comm';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        try {
            [$options, $operands] = Getopt::parse($args, '123z', self::LONG);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->usageError($invalidArgumentException->getMessage());
        }

        $show = [1 => true, 2 => true, 3 => true];
        $check = null;
        $separator = null;
        $total = false;
        $eol = "\n";

        foreach ($options as [$option, $value]) {
            match ($option) {
                '1', '2', '3' => $show[(int) $option] = false,
                'c', 'C' => $check = $option === 'c',
                't' => $total = true,
                'z' => $eol = "\0",
                default => $separator = $separator === null || $separator === $value ? $value : false,
            };
        }

        if ($separator === false) {
            return $this->failure("comm: multiple output delimiters specified\n");
        }

        if (count($operands) < 2) {
            return $this->usageError($operands === [] ? 'missing operand' : sprintf("missing operand after '%s'", $operands[0]));
        }

        if (isset($operands[2])) {
            return $this->usageError(sprintf("extra operand '%s'", $operands[2]));
        }

        // An empty delimiter is a NUL.
        $separator = ($separator ?? "\t") ?: "\0";
        $lines = [];

        foreach ($operands as $i => $file) {
            try {
                // Both operands `-` share stdin, so the second finds it empty.
                $content = $i === 1 && $operands === ['-', '-'] ? '' : $this->readOperand($commandContext, $file);
            } catch (RuntimeException $runtimeException) {
                return $this->failure(sprintf("comm: %s: %s\n", $this->shellEscape($file), $this->describeError($runtimeException)));
            }

            $lines[] = $content === '' ? [] : explode($eol, str_ends_with($content, $eol) ? substr($content, 0, -1) : $content);
        }

        $output = '';
        $stderr = '';
        $counts = [1 => 0, 2 => 0, 3 => 0];
        $at = [0, 0];
        $unpairable = false;
        $warned = [false, false];

        while (isset($lines[0][$at[0]]) || isset($lines[1][$at[1]])) {
            $order = match (true) {
                ! isset($lines[0][$at[0]]) => 1,
                ! isset($lines[1][$at[1]]) => -1,
                default => strcmp($lines[0][$at[0]], $lines[1][$at[1]]),
            };
            $column = $order === 0 ? 3 : ($order < 0 ? 1 : 2);
            $unpairable = $unpairable || $order !== 0;
            $counts[$column]++;

            if ($show[$column]) {
                $prefix = $column === 1 ? 0 : ($column === 2 ? (int) $show[1] : (int) $show[1] + (int) $show[2]);
                $output .= str_repeat($separator, $prefix).($column === 2 ? $lines[1][$at[1]] : $lines[0][$at[0]]).$eol;
            }

            foreach ([0, 1] as $file) {
                if ($file === 0 ? $order > 0 : $order < 0) {
                    continue;
                }

                $next = ++$at[$file];
                // At the end of a file the last two lines are checked again, as a line may have gone unpaired since.
                $previous = isset($lines[$file][$next]) ? $next - 1 : $next - 2;

                // By default order is checked only once a line has had no partner.
                if ($previous >= 0 && ! $warned[$file] && ($check ?? $unpairable) && strcmp($lines[$file][$previous], $lines[$file][$previous + 1]) > 0) {
                    $stderr .= sprintf("comm: file %d is not in sorted order\n", $file + 1);

                    if ($check === true) {
                        return $this->failure($stderr, 1, $output);
                    }

                    $warned[$file] = true;
                }
            }
        }

        // GNU closes stdin twice when both operands are `-`, and dies on the second.
        if ($operands === ['-', '-']) {
            return $this->failure($stderr."comm: -: Bad file descriptor\n", 1, $output);
        }

        if ($total) {
            $output .= implode($separator, $counts).$separator.'total'.$eol;
        }

        return in_array(true, $warned, true) ? $this->failure($stderr."comm: input is not in sorted order\n", 1, $output) : $this->success($output);
    }
}
