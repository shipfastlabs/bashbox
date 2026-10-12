<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\Exceptions\ExecutionLimitException;
use BashBox\ExecResult;
use BashBox\Filesystem\FsStat;
use InvalidArgumentException;
use RuntimeException;

/** Two-file text diff. Hirschberg LCS keeps working memory linear in the input lines. */
final class Diff_ extends AbstractCommand
{
    private const array LONG = [
        'unified' => ['v', null],
        'brief' => ['q', false],
        'report-identical-files' => ['s', false],
        'ignore-case' => ['i', false],
        'ignore-all-space' => ['w', false],
        'ignore-space-change' => ['b', false],
        'label' => ['L', true],
    ];

    public function getName(): string
    {
        return 'diff';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        try {
            [$options, $files] = Getopt::parse($args, 'uU:qsiwbL:', self::LONG);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->usageError($invalidArgumentException->getMessage(), 2);
        }

        $context = null;
        $flags = [];
        $labels = [];

        foreach ($options as [$option, $value]) {
            if ($option === 'L') {
                $labels[] = $value;
            } elseif (in_array($option, ['u', 'U', 'v'], true)) {
                if (($option === 'U' || $value !== '') && (! ctype_digit($value) || strlen($value) > 9)) {
                    return $this->usageError('invalid context length: '.$value, 2);
                }

                $context = $value === '' ? 3 : (int) $value;
            } else {
                $flags[$option] = true;
            }
        }

        if (count($files) !== 2 || count($labels) > 2) {
            return $this->usageError('expected exactly two operands and at most two labels', 2);
        }

        $contents = [];
        $times = [];
        $bytes = 0;

        foreach ($files as $file) {
            try {
                $stat = $file === '-' || $file === '' ? null : $commandContext->fs->stat($this->resolvePath($commandContext, $file));

                if ($stat?->isDirectory) {
                    return $this->failure(sprintf("diff: %s: Is a directory\n", $this->shellEscape($file)), 2);
                }

                // Check file sizes before reading, then check again for stdin / unusual virtual files.
                if ($stat instanceof FsStat && $stat->size > $commandContext->limits->maxStringLength - $bytes) {
                    throw new ExecutionLimitException('diff: input size limit exceeded');
                }

                $content = $this->readOperand($commandContext, $file);
            } catch (RuntimeException $runtimeException) {
                if ($runtimeException instanceof ExecutionLimitException) {
                    throw $runtimeException;
                }

                return $this->failure(sprintf("diff: %s: %s\n", $this->shellEscape($file), $this->describeError($runtimeException)), 2);
            }

            $bytes += strlen($content);

            if ($bytes > $commandContext->limits->maxStringLength) {
                throw new ExecutionLimitException('diff: input size limit exceeded');
            }

            $contents[] = $content;
            $times[] = "\t".gmdate('Y-m-d H:i:s', $stat->mtime ?? 0).'.000000000 +0000';
        }

        $names = [$labels[0] ?? $files[0], $labels[1] ?? $files[1]];
        $output = '';

        if ($contents[0] === $contents[1]) {
            if (isset($flags['s'])) {
                $this->append($commandContext, $output, 'Files ', $names[0], ' and ', $names[1], " are identical\n");
            }

            return $this->success($output);
        }

        $binary = str_contains($contents[0], "\0") || str_contains($contents[1], "\0");

        if ($binary) {
            $this->append($commandContext, $output, isset($flags['q']) ? 'Files ' : 'Binary files ', $names[0], ' and ', $names[1], " differ\n");

            return $this->failure('', 1, $output);
        }

        $lines = [];
        $keys = [];
        $elements = 0;

        foreach ($contents as $content) {
            $count = substr_count($content, "\n") + (int) ($content !== '' && ! str_ends_with($content, "\n"));
            $elements += $count;

            if ($elements > $commandContext->limits->maxArrayElements) {
                throw new ExecutionLimitException('diff: line count limit exceeded');
            }
        }

        foreach ($contents as $content) {
            $split = $this->splitLines($content);
            $lines[] = $split;
            $normalized = [];

            foreach ($split['lines'] as $i => $line) {
                if (isset($flags['w'])) {
                    $line = (string) preg_replace('/[\t\r\v\f ]+/', '', $line);
                } elseif (isset($flags['b'])) {
                    $line = (string) preg_replace('/[\t\r\v\f ]+/', ' ', rtrim($line, "\t\r\v\f "));
                }

                if (isset($flags['i'])) {
                    $line = strtolower($line);
                }

                // Whitespace-ignore modes also ignore a missing final newline, as GNU diff does.
                $normalized[] = $line.((isset($flags['w']) || isset($flags['b']) || $split['trailingNewline'] || $i < count($split['lines']) - 1) ? "\n" : '');
            }

            $keys[] = $normalized;
        }

        if ($keys[0] === $keys[1]) {
            if (isset($flags['s'])) {
                $this->append($commandContext, $output, 'Files ', $names[0], ' and ', $names[1], " are identical\n");
            }

            return $this->success($output);
        }

        if (isset($flags['q'])) {
            $this->append($commandContext, $output, 'Files ', $names[0], ' and ', $names[1], " differ\n");

            return $this->failure('', 1, $output);
        }

        $n = count($keys[0]);
        $m = count($keys[1]);
        $prefix = 0;

        while ($prefix < min($n, $m) && $keys[0][$prefix] === $keys[1][$prefix]) {
            $prefix++;
        }

        $endA = $n;
        $endB = $m;

        while ($endA > $prefix && $endB > $prefix && $keys[0][$endA - 1] === $keys[1][$endB - 1]) {
            $endA--;
            $endB--;
        }

        // ponytail: quadratic time, not space; reject expensive middles before allocating LCS rows.
        // 3*n*m bounds all cell comparisons, including odd-sized recursive splits.
        if (($endA - $prefix) > 0 && ($endB - $prefix) > intdiv($commandContext->limits->maxLoopIterations, 3 * ($endA - $prefix))) {
            throw new ExecutionLimitException('diff: comparison work limit exceeded');
        }

        $matches = [];
        $this->matches($keys[0], $prefix, $endA, $keys[1], $prefix, $endB, $matches);
        $matches[] = [$endA, $endB];
        $changes = [];
        $a = $prefix;
        $b = $prefix;

        foreach ($matches as [$x, $y]) {
            if ($x > $a || $y > $b) {
                $changes[] = [$a, $x, $b, $y];
            }

            $a = $x + 1;
            $b = $y + 1;
        }

        if ($context === null) {
            foreach ($changes as [$a, $x, $b, $y]) {
                $this->append($commandContext, $output, $this->range($a, $x, false).($a === $x ? 'a' : ($b === $y ? 'd' : 'c')).$this->range($b, $y, false)."\n");
                $this->emitLines($commandContext, $output, $lines[0], $a, $x, '< ');

                if ($a !== $x && $b !== $y) {
                    $this->append($commandContext, $output, "---\n");
                }

                $this->emitLines($commandContext, $output, $lines[1], $b, $y, '> ');
            }
        } else {
            $this->append($commandContext, $output, '--- ', $names[0], isset($labels[0]) ? '' : $times[0], "\n+++ ", $names[1], isset($labels[1]) ? '' : $times[1], "\n");

            for ($i = 0, $count = count($changes); $i < $count; $i++) {
                [$a, $x, $b, $y] = $changes[$i];
                $startA = max(0, $a - $context);
                $startB = max(0, $b - $context);
                $last = $i;

                while ($last + 1 < $count && $changes[$last + 1][0] - $x <= 2 * $context) {
                    $last++;
                    $x = $changes[$last][1];
                    $y = $changes[$last][3];
                }

                $end = min($n, $x + $context);
                $this->append($commandContext, $output, '@@ -'.$this->range($startA, $end, true).' +'.$this->range($startB, min($m, $y + $context), true)." @@\n");
                $cursor = $startA;

                for (; $i <= $last; $i++) {
                    [$a, $x, $b, $y] = $changes[$i];
                    $this->emitLines($commandContext, $output, $lines[0], $cursor, $a, ' ');
                    $this->emitLines($commandContext, $output, $lines[0], $a, $x, '-');
                    $this->emitLines($commandContext, $output, $lines[1], $b, $y, '+');
                    $cursor = $x;
                }

                $this->emitLines($commandContext, $output, $lines[0], $cursor, $end, ' ');
                $i--;
            }
        }

        return $this->failure('', 1, $output);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @param  list<array{int, int}>  $matches
     */
    private function matches(array $a, int $startA, int $endA, array $b, int $startB, int $endB, array &$matches): void
    {
        if ($startA === $endA || $startB === $endB) {
            return;
        }

        if ($endA - $startA === 1) {
            for ($j = $startB; $j < $endB; $j++) {
                if ($a[$startA] === $b[$j]) {
                    $matches[] = [$startA, $j];

                    return;
                }
            }

            return;
        }

        $middle = intdiv($startA + $endA, 2);
        $left = $this->row($a, $startA, $middle, $b, $startB, $endB, false);
        $right = $this->row($a, $middle, $endA, $b, $startB, $endB, true);
        $width = $endB - $startB;
        $best = -1;
        $split = 0;

        for ($j = 0; $j <= $width; $j++) {
            $score = $left[$j] + $right[$width - $j];

            if ($score > $best) {
                $best = $score;
                $split = $j;
            }
        }

        unset($left, $right);
        $this->matches($a, $startA, $middle, $b, $startB, $startB + $split, $matches);
        $this->matches($a, $middle, $endA, $b, $startB + $split, $endB, $matches);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return array<int, int> LCS lengths indexed by prefix length
     */
    private function row(array $a, int $startA, int $endA, array $b, int $startB, int $endB, bool $reverse): array
    {
        $width = $endB - $startB;
        $row = array_fill(0, $width + 1, 0);

        for ($i = 0; $i < $endA - $startA; $i++) {
            $diagonal = 0;

            for ($j = 1; $j <= $width; $j++) {
                $previous = $row[$j];
                $row[$j] = $a[$reverse ? $endA - 1 - $i : $startA + $i] === $b[$reverse ? $endB - $j : $startB + $j - 1] ? $diagonal + 1 : max($row[$j], $row[$j - 1]);
                $diagonal = $previous;
            }
        }

        return $row;
    }

    private function range(int $start, int $end, bool $unified): string
    {
        $count = $end - $start;

        if ($unified) {
            return ($count === 0 ? $start : $start + 1).($count === 1 ? '' : ','.$count);
        }

        return sprintf('%d%s', $count === 0 ? $start : $start + 1, $count > 1 ? ','.$end : '');
    }

    /** @param array{lines: list<string>, trailingNewline: bool} $file */
    private function emitLines(CommandContext $commandContext, string &$output, array $file, int $start, int $end, string $prefix): void
    {
        for ($i = $start; $i < $end; $i++) {
            $line = $file['lines'][$i];
            $marker = ! $file['trailingNewline'] && $i === count($file['lines']) - 1 ? "\\ No newline at end of file\n" : '';
            $this->checkOutputSize($commandContext, strlen($output) + strlen($prefix) + strlen($line) + 1 + strlen($marker));
            $output .= $prefix.$line."\n".$marker;
        }
    }

    private function append(CommandContext $commandContext, string &$output, string ...$parts): void
    {
        $size = strlen($output);

        foreach ($parts as $part) {
            $size += strlen($part);
        }

        $this->checkOutputSize($commandContext, $size);

        foreach ($parts as $part) {
            $output .= $part;
        }
    }
}
