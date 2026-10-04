<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use BashBox\Filesystem\FsStat;
use InvalidArgumentException;
use RuntimeException;

/** Disk usage in 1K blocks: a file takes its size rounded up, while directories and symlinks take none, like ls -l's total. */
final class Du extends AbstractCommand
{
    private const array LONG = [
        'all' => ['a', false],
        'apparent-size' => ['apparent-size', false],
        'bytes' => ['b', false],
        'human-readable' => ['h', false],
        'max-depth' => ['d', true],
        'summarize' => ['s', false],
        'total' => ['c', false],
    ];

    private bool $all = false;

    private bool $apparent = false;

    /** Bytes per output unit; 0 for human-readable sizes. */
    private int $unit = 1024;

    private int $maxDepth = PHP_INT_MAX;

    /** @var array<int, true> inodes already counted */
    private array $seen = [];

    private string $output = '';

    public function getName(): string
    {
        return 'du';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        try {
            [$options, $operands] = Getopt::parse($args, 'abcd:hks', self::LONG);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->usageError($invalidArgumentException->getMessage());
        }

        $flags = array_column($options, 1, 0);
        $this->all = isset($flags['a']);
        $this->apparent = isset($flags['apparent-size']) || isset($flags['b']);
        $this->unit = 1024;
        $depth = null;

        // -b, -k and -h each set the unit, so the last one wins.
        foreach ($options as [$option]) {
            $this->unit = ['b' => 1, 'k' => 1024, 'h' => 0][$option] ?? $this->unit;
        }

        if (isset($flags['d'])) {
            $depth = preg_match('/^\s*[-+]?(?:0[xX][\da-fA-F]+|0[0-7]*|[1-9]\d*)$/', $flags['d']) === 1 ? intval(ltrim($flags['d']), 0) : PHP_INT_MAX;

            if (in_array($depth, [PHP_INT_MAX, PHP_INT_MIN], true)) {
                return $this->usageError(sprintf("invalid maximum depth '%s'", $flags['d']));
            }
        }

        if (isset($flags['s']) && $this->all) {
            return $this->usageError('cannot both summarize and show all entries');
        }

        if (isset($flags['s']) && $depth !== null && $depth !== 0) {
            return $this->usageError(sprintf('warning: summarizing conflicts with --max-depth=%d', $depth));
        }

        $stderr = isset($flags['s']) && $depth === 0 ? "du: warning: summarizing is the same as using --max-depth=0\n" : '';
        $this->maxDepth = isset($flags['s']) ? 0 : $depth ?? PHP_INT_MAX;
        $this->seen = [];
        $this->output = '';
        $total = [0, 0];
        $status = 0;

        foreach ($operands ?: ['.'] as $operand) {
            if ($operand === '') {
                $stderr .= "du: invalid zero-length file name\n";
                $status = 1;

                continue;
            }

            $path = $this->resolvePath($commandContext, $operand);

            try {
                $stat = $commandContext->fs->lstat($path);
            } catch (RuntimeException $runtimeException) {
                $stderr .= sprintf("du: cannot access %s: %s\n", $this->shellEscape($operand, true), $this->describeError($runtimeException));
                $status = 1;

                continue;
            }

            // With several operands everything is remembered, so a repeated or nested operand is counted once.
            [$blocks, $bytes] = $this->walk($commandContext, $path, $stat, $operand, 0, count($operands) > 1);
            $total = [$total[0] + $blocks, $total[1] + $bytes];
        }

        if (isset($flags['c'])) {
            $this->output .= $this->size($total)."\ttotal\n";
        }

        return new ExecResult($this->output, $stderr, $status);
    }

    /**
     * Prints an entry after everything below it, returning its usage in blocks and bytes.
     *
     * @return array{int, int}
     */
    private function walk(CommandContext $commandContext, string $path, FsStat $fsStat, string $name, int $depth, bool $rememberAll): array
    {
        if ($rememberAll || (! $fsStat->isDirectory && $fsStat->nlink > 1)) {
            if (isset($this->seen[$fsStat->ino])) {
                return [0, 0];
            }

            $this->seen[$fsStat->ino] = true;
        }

        $usage = $fsStat->isFile ? [(int) ceil($fsStat->size / 1024), $fsStat->size] : [0, $fsStat->isSymbolicLink ? $fsStat->size : 0];

        if ($fsStat->isDirectory) {
            foreach ($commandContext->fs->readdir($path) as $child) {
                $childPath = $path === '/' ? '/'.$child : $path.'/'.$child;
                [$blocks, $bytes] = $this->walk($commandContext, $childPath, $commandContext->fs->lstat($childPath), rtrim($name, '/').'/'.$child, $depth + 1, $rememberAll);
                $usage = [$usage[0] + $blocks, $usage[1] + $bytes];
            }
        }

        if ($depth === 0 || ($depth <= $this->maxDepth && ($fsStat->isDirectory || $this->all))) {
            $this->output .= $this->size($usage)."\t".$name."\n";
            $this->checkOutputSize($commandContext, strlen($this->output));
        }

        return $usage;
    }

    /** @param array{int, int} $usage */
    private function size(array $usage): string
    {
        $bytes = $this->apparent ? $usage[1] : $usage[0] * 1024;

        return $this->unit === 0 ? $this->human($bytes) : (string) (int) ceil($bytes / $this->unit);
    }

    /** GNU's -h: powers of 1024, rounded up, with one decimal below 10. */
    private function human(int $bytes): string
    {
        $power = 0;

        while ($bytes >= 1024 ** ($power + 1) && $power < 8) {
            $power++;
        }

        if ($power === 0) {
            return (string) $bytes;
        }

        $tenths = (int) ceil($bytes * 10 / 1024 ** $power);
        $whole = (int) ceil($bytes / 1024 ** $power);

        return match (true) {
            $tenths < 100 => sprintf('%.1f%s', $tenths / 10, 'KMGTPEZY'[$power - 1]),
            $whole < 1024 => $whole.'KMGTPEZY'[$power - 1],
            default => '1.0'.'KMGTPEZY'[$power],
        };
    }
}
