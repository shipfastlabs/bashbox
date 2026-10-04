<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use BashBox\Filesystem\DirentEntry;
use RuntimeException;

/** Mirrors tree 2.x with ASCII line drawing (the non-UTF-8 locale default); only -a is supported. */
final class Tree_ extends AbstractCommand
{
    private int $dirCount = 0;

    private int $fileCount = 0;

    private bool $failed = false;

    public function getName(): string
    {
        return 'tree';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->getopt($args, 'a');

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $operands] = $parsed;
        $showHidden = isset($flags['a']);

        $this->dirCount = 0;
        $this->fileCount = 0;
        $this->failed = false;
        $output = '';

        foreach ($operands === [] ? ['.'] : $operands as $path) {
            $resolved = $this->resolvePath($commandContext, $path);

            if (! $commandContext->fs->exists($resolved)) {
                $output .= $path."  [error opening dir]\n";
                $this->failed = true;

                continue;
            }

            try {
                $listing = $this->buildTree($commandContext, $resolved, '', $showHidden);
            } catch (RuntimeException) {
                // A file (or unreadable directory) operand is named, counted as a file, and skipped.
                $output .= $path."  [error opening dir]\n";
                $this->fileCount++;

                continue;
            }

            // tree counts a top-level directory only when it has visible entries.
            $this->dirCount += $listing === '' ? 0 : 1;
            $output .= $path."\n".$listing;
        }

        $output .= sprintf(
            "\n%d director%s, %d file%s\n",
            $this->dirCount,
            $this->dirCount === 1 ? 'y' : 'ies',
            $this->fileCount,
            $this->fileCount === 1 ? '' : 's',
        );

        return new ExecResult(stdout: $output, stderr: '', exitCode: $this->failed ? 2 : 0);
    }

    private function buildTree(CommandContext $commandContext, string $path, string $prefix, bool $showHidden): string
    {
        $entries = array_values(array_filter(
            $commandContext->fs->readdirWithFileTypes($path),
            fn (DirentEntry $direntEntry): bool => $showHidden || ! str_starts_with($direntEntry->name, '.'),
        ));

        $output = '';
        $last = count($entries) - 1;

        foreach ($entries as $i => $entry) {
            $childPath = rtrim($path, '/').'/'.$entry->name;
            $line = $prefix.($i === $last ? '`-- ' : '|-- ').$entry->name;

            if ($entry->isSymbolicLink) {
                // Symlinks are shown with their target and never descended into.
                $line .= ' -> '.$commandContext->fs->readlink($childPath);

                try {
                    $isDirectory = $commandContext->fs->stat($childPath)->isDirectory;
                } catch (RuntimeException) {
                    $isDirectory = false;
                }

                $isDirectory ? $this->dirCount++ : $this->fileCount++;
                $output .= $line."\n";

                continue;
            }

            if (! $entry->isDirectory) {
                $this->fileCount++;
                $output .= $line."\n";

                continue;
            }

            $this->dirCount++;

            try {
                $children = $this->buildTree($commandContext, $childPath, $prefix.($i === $last ? '    ' : '|   '), $showHidden);
                $output .= $line."\n".$children;
            } catch (RuntimeException) {
                $output .= $line."  [error opening dir]\n";
                $this->failed = true;
            }
        }

        return $output;
    }
}
