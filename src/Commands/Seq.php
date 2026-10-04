<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Seq extends AbstractCommand
{
    private const array LONG = [
        'format' => ['f', true],
        'separator' => ['s', true],
        'equal-width' => ['w', false],
    ];

    public function getName(): string
    {
        return 'seq';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        // Options end at the first operand, and a negative number is an operand.
        $parsed = $this->getopt($args, '+f:s:w', self::LONG, numbers: true);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $operands] = $parsed;

        if ($operands === []) {
            return $this->usageError('missing operand');
        }

        if (isset($operands[3])) {
            return $this->usageError(sprintf("extra operand '%s'", $operands[3]));
        }

        foreach ($operands as $operand) {
            if (! is_numeric($operand) && preg_match('/^[-+]?inf(inity)?$/i', $operand) !== 1) {
                return $this->usageError(sprintf("invalid floating point argument: '%s'", $operand));
            }
        }

        [$first, $step, $last] = match (count($operands)) {
            1 => ['1', '1', $operands[0]],
            2 => [$operands[0], '1', $operands[1]],
            default => $operands,
        };

        if ((float) $step === 0.0) {
            return $this->usageError(sprintf("invalid Zero increment value: '%s'", $step));
        }

        if (isset($flags['f'], $flags['w'])) {
            return $this->usageError('format string may not be specified when printing equal width strings');
        }

        $error = isset($flags['f']) ? $this->formatError($flags['f']) : null;

        if ($error !== null) {
            return $this->failure(sprintf("seq: format '%s' %s\n", $flags['f'], $error));
        }

        // Without -f, print as many decimals as the most precise of FIRST and INCREMENT.
        $decimals = max(array_map(fn (string $n): int => strlen(strrchr($n, '.') ?: '.') - 1, [$first, $step]));
        [$first, $step, $last] = array_map(fn (string $n): float => is_numeric($n) ? (float) $n : ($n[0] === '-' ? -INF : INF), [$first, $step, $last]);
        $format = fn (float $n): string => sprintf('%.'.$decimals.'F', $n);
        // -w pads with zeros to the width of the wider of FIRST and LAST.
        $width = isset($flags['w']) ? max(strlen($format($first)), strlen($format($last))) : 0;

        if (isset($flags['f'])) {
            // Seq doesn't expand backslash escapes, so printf gets them escaped.
            $printf = new Printf_;
            $format = fn (float $n): string => $printf->execute([str_replace('\\', '\\\\', $flags['f']), sprintf('%.17g', $n)], $commandContext)->stdout;
        }

        $separator = $flags['s'] ?? "\n";
        $direction = $step <=> 0;
        $output = '';

        // Rounded to the operands' precision, so float error can't drop the last value (`seq 0.1 0.1 0.3`).
        for ($i = 0; round($value = $first + $i * $step, $decimals) * $direction <= $last * $direction; $i++) {
            $number = $format($value);
            $number = str_starts_with($number, '-') ? '-'.str_pad(substr($number, 1), $width - 1, '0', STR_PAD_LEFT) : str_pad($number, $width, '0', STR_PAD_LEFT);
            $output .= ($i === 0 ? '' : $separator).$number;
            $this->checkOutputSize($commandContext, strlen($output));
        }

        return $this->success($output === '' ? '' : $output."\n");
    }

    /** What GNU finds wrong with a -f format: it needs exactly one floating-point directive. */
    private function formatError(string $format): ?string
    {
        if (preg_match('/^(?:[^%]|%%)*+%[-+#0 \']*\d*(?:\.\d*)?L?(.?)/s', $format, $m) !== 1) {
            return 'has no % directive';
        }

        return match (true) {
            $m[1] === '' => 'ends in %',
            ! str_contains('aAeEfFgG', $m[1]) => sprintf('has unknown %%%s directive', $m[1]),
            preg_match('/^(?:[^%]|%%)*+%/', substr($format, strlen($m[0]))) === 1 => 'has too many % directives',
            default => null,
        };
    }
}
