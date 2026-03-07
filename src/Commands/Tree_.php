<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Tree_ extends AbstractCommand
{
    private int $dirCount = 0;

    private int $fileCount = 0;

    public function getName(): string
    {
        return 'tree';
    }

    public function execute(array $args, CommandContext $ctx): ExecResult
    {
        $this->dirCount = 0;
        $this->fileCount = 0;

        $path = $args[0] ?? '.';

        if (! str_starts_with($path, '/')) {
            $path = $ctx->fs->resolvePath($ctx->cwd, $path);
        }

        if (! $ctx->fs->exists($path)) {
            return $this->failure($path.' [error opening dir]

0 directories, 0 files
');
        }

        try {
            $stat = $ctx->fs->stat($path);
            if (! $stat->isDirectory) {
                return $this->failure($path.' [error opening dir]

0 directories, 0 files
');
            }
        } catch (RuntimeException) {
            return $this->failure($path.' [error opening dir]

0 directories, 0 files
');
        }

        $output = $path."\n";
        $output .= $this->buildTree($ctx, $path, '');
        $output .= "\n{$this->dirCount} directories, {$this->fileCount} files\n";

        return $this->success($output);
    }

    private function buildTree(CommandContext $ctx, string $path, string $prefix): string
    {
        $output = '';

        try {
            $entries = $ctx->fs->readdirWithFileTypes($path);
        } catch (RuntimeException) {
            return $output;
        }

        // Sort entries alphabetically
        usort($entries, fn ($a, $b): int => strcmp((string) $a->name, (string) $b->name));

        $count = count($entries);

        foreach ($entries as $i => $entry) {
            $isLast = ($i === $count - 1);
            $connector = $isLast ? '`-- ' : '|-- ';
            $childPrefix = $isLast ? '    ' : '|   ';

            $output .= $prefix.$connector.$entry->name."\n";

            if ($entry->isDirectory) {
                $this->dirCount++;
                $childPath = $path.'/'.$entry->name;
                $output .= $this->buildTree($ctx, $childPath, $prefix.$childPrefix);
            } else {
                $this->fileCount++;
            }
        }

        return $output;
    }
}
