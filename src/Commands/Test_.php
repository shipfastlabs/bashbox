<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Test_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'test';
    }

    public function execute(array $args, CommandContext $ctx): ExecResult
    {
        // Remove trailing ] if invoked as [
        if ($args !== [] && $args[count($args) - 1] === ']') {
            array_pop($args);
        }

        if ($args === []) {
            return $this->failure();
        }

        $result = $this->evaluate($args, $ctx, 0, count($args));

        return $result ? $this->success() : $this->failure();
    }

    /**
     * @param  list<string>  $args
     */
    private function evaluate(array $args, CommandContext $ctx, int $start, int $end): bool
    {
        $length = $end - $start;

        if ($length === 0) {
            return false;
        }

        // Look for -o (OR) at the top level (lowest precedence)
        for ($i = $start; $i < $end; $i++) {
            if ($args[$i] === '-o') {
                $left = $this->evaluate($args, $ctx, $start, $i);
                $right = $this->evaluate($args, $ctx, $i + 1, $end);

                return $left || $right;
            }
        }

        // Look for -a (AND)
        for ($i = $start; $i < $end; $i++) {
            if ($args[$i] === '-a') {
                $left = $this->evaluate($args, $ctx, $start, $i);
                $right = $this->evaluate($args, $ctx, $i + 1, $end);

                return $left && $right;
            }
        }

        // Unary ! (NOT)
        if ($length >= 2 && $args[$start] === '!') {
            return ! $this->evaluate($args, $ctx, $start + 1, $end);
        }

        // Single argument: true if non-empty string
        if ($length === 1) {
            return $args[$start] !== '';
        }

        // Unary operators
        if ($length === 2) {
            $op = $args[$start];
            $val = $args[$start + 1];

            return match ($op) {
                '-z' => $val === '',
                '-n' => $val !== '',
                '-e' => $this->fileExists($ctx, $val),
                '-f' => $this->isFile($ctx, $val),
                '-d' => $this->isDirectory($ctx, $val),
                '-r' => $this->isReadable($ctx, $val),
                '-w' => $this->isWritable($ctx, $val),
                '-x' => $this->isExecutable($ctx, $val),
                '-s' => $this->isNonEmptyFile($ctx, $val),
                default => $val !== '',
            };
        }

        // Binary operators
        if ($length === 3) {
            $left = $args[$start];
            $op = $args[$start + 1];
            $right = $args[$start + 2];

            return match ($op) {
                '=' , '==' => $left === $right,
                '!=' => $left !== $right,
                '-eq' => (int) $left === (int) $right,
                '-ne' => (int) $left !== (int) $right,
                '-lt' => (int) $left < (int) $right,
                '-le' => (int) $left <= (int) $right,
                '-gt' => (int) $left > (int) $right,
                '-ge' => (int) $left >= (int) $right,
                default => false,
            };
        }

        return false;
    }

    private function fileExists(CommandContext $ctx, string $path): bool
    {
        return $ctx->fs->exists($this->resolvePath($ctx, $path));
    }

    private function isFile(CommandContext $ctx, string $path): bool
    {
        $resolved = $this->resolvePath($ctx, $path);

        if (! $ctx->fs->exists($resolved)) {
            return false;
        }

        try {
            $stat = $ctx->fs->stat($resolved);

            return $stat->isFile;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function isDirectory(CommandContext $ctx, string $path): bool
    {
        $resolved = $this->resolvePath($ctx, $path);

        if (! $ctx->fs->exists($resolved)) {
            return false;
        }

        try {
            $stat = $ctx->fs->stat($resolved);

            return $stat->isDirectory;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function isReadable(CommandContext $ctx, string $path): bool
    {
        $resolved = $this->resolvePath($ctx, $path);

        if (! $ctx->fs->exists($resolved)) {
            return false;
        }

        try {
            $stat = $ctx->fs->stat($resolved);

            return ($stat->mode & 0o444) !== 0;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function isWritable(CommandContext $ctx, string $path): bool
    {
        $resolved = $this->resolvePath($ctx, $path);

        if (! $ctx->fs->exists($resolved)) {
            return false;
        }

        try {
            $stat = $ctx->fs->stat($resolved);

            return ($stat->mode & 0o222) !== 0;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function isExecutable(CommandContext $ctx, string $path): bool
    {
        $resolved = $this->resolvePath($ctx, $path);

        if (! $ctx->fs->exists($resolved)) {
            return false;
        }

        try {
            $stat = $ctx->fs->stat($resolved);

            return ($stat->mode & 0o111) !== 0;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function isNonEmptyFile(CommandContext $ctx, string $path): bool
    {
        $resolved = $this->resolvePath($ctx, $path);

        if (! $ctx->fs->exists($resolved)) {
            return false;
        }

        try {
            $stat = $ctx->fs->stat($resolved);

            return $stat->isFile && $stat->size > 0;
        } catch (RuntimeException) {
            return false;
        }
    }
}
