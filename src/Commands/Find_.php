<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Find_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'find';
    }

    public function execute(array $args, CommandContext $ctx): ExecResult
    {
        // Parse find arguments: find [path...] [expression]
        $paths = [];
        $namePattern = '';
        $typeFilter = '';
        $maxDepth = -1;

        $i = 0;
        while ($i < count($args)) {
            $arg = $args[$i];

            if ($arg === '-name') {
                $i++;
                $namePattern = $args[$i] ?? '';
            } elseif ($arg === '-type') {
                $i++;
                $typeFilter = $args[$i] ?? '';
            } elseif ($arg === '-maxdepth') {
                $i++;
                $maxDepth = (int) ($args[$i] ?? '0');
            } elseif (! str_starts_with($arg, '-')) {
                $paths[] = $arg;
            }

            $i++;
        }

        if ($paths === []) {
            $paths = ['.'];
        }

        $output = '';

        foreach ($paths as $searchPath) {
            $resolvedPath = $this->resolvePath($ctx, $searchPath);

            try {
                $stat = $ctx->fs->stat($resolvedPath);
            } catch (RuntimeException) {
                return $this->failure("find: '{$searchPath}': No such file or directory\n");
            }

            if ($stat->isFile) {
                $name = basename($searchPath);
                if ($this->matchesFilters($name, true, false, $namePattern, $typeFilter)) {
                    $output .= $searchPath."\n";
                }
            } else {
                $this->walkDirectory(
                    $ctx,
                    $resolvedPath,
                    $searchPath,
                    $namePattern,
                    $typeFilter,
                    $maxDepth,
                    0,
                    $output,
                );
            }
        }

        return $this->success($output);
    }

    private function walkDirectory(
        CommandContext $ctx,
        string $absolutePath,
        string $displayPath,
        string $namePattern,
        string $typeFilter,
        int $maxDepth,
        int $currentDepth,
        string &$output,
    ): void {
        // Check the directory itself
        $dirName = basename($displayPath);
        if ($displayPath === '.') {
            $dirName = '.';
        }

        if ($this->matchesFilters($dirName, false, true, $namePattern, $typeFilter)) {
            $output .= $displayPath."\n";
        }

        if ($maxDepth >= 0 && $currentDepth >= $maxDepth) {
            return;
        }

        try {
            $entries = $ctx->fs->readdirWithFileTypes($absolutePath);
        } catch (RuntimeException) {
            return;
        }

        // Sort entries for consistent output
        usort($entries, fn ($a, $b): int => strcmp((string) $a->name, (string) $b->name));

        foreach ($entries as $entry) {
            $childAbsolute = $absolutePath.'/'.$entry->name;
            $childDisplay = $displayPath === '.' ? './'.$entry->name : $displayPath.'/'.$entry->name;

            if ($entry->isDirectory) {
                $this->walkDirectory(
                    $ctx,
                    $childAbsolute,
                    $childDisplay,
                    $namePattern,
                    $typeFilter,
                    $maxDepth,
                    $currentDepth + 1,
                    $output,
                );
            } elseif ($entry->isFile) {
                if ($this->matchesFilters($entry->name, true, false, $namePattern, $typeFilter)) {
                    $output .= $childDisplay."\n";
                }
            }
        }
    }

    private function matchesFilters(string $name, bool $isFile, bool $isDirectory, string $namePattern, string $typeFilter): bool
    {
        if ($typeFilter !== '') {
            if ($typeFilter === 'f' && ! $isFile) {
                return false;
            }

            if ($typeFilter === 'd' && ! $isDirectory) {
                return false;
            }
        }

        if ($namePattern !== '' && ! $this->matchGlob($name, $namePattern)) {
            return false;
        }

        return true;
    }

    private function matchGlob(string $name, string $pattern): bool
    {
        // Convert glob pattern to regex
        $regex = '';
        $len = strlen($pattern);

        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];
            $regex .= match ($ch) {
                '*' => '.*',
                '?' => '.',
                '.' => '\\.',
                '\\' => '\\\\',
                '^' => '\\^',
                '$' => '\\$',
                '|' => '\\|',
                '(' => '\\(',
                ')' => '\\)',
                '+' => '\\+',
                '{' => '\\{',
                '}' => '\\}',
                '[' => '[',
                ']' => ']',
                default => $ch,
            };
        }

        return (bool) preg_match('/^'.$regex.'$/s', $name);
    }
}
