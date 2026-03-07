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

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        // Remove trailing ] if invoked as [
        if ($args !== [] && $args[count($args) - 1] === ']') {
            array_pop($args);
        }

        if ($args === []) {
            return $this->failure();
        }

        $result = $this->evaluate($args, $commandContext, 0, count($args));

        return $result ? $this->success() : $this->failure();
    }

    /**
     * @param  list<string>  $args
     */
    private function evaluate(array $args, CommandContext $commandContext, int $start, int $end): bool
    {
        $length = $end - $start;

        if ($length === 0) {
            return false;
        }

        // Look for -o (OR) at the top level (lowest precedence)
        for ($i = $start; $i < $end; $i++) {
            if ($args[$i] === '-o') {
                $left = $this->evaluate($args, $commandContext, $start, $i);
                $right = $this->evaluate($args, $commandContext, $i + 1, $end);

                return $left || $right;
            }
        }

        // Look for -a (AND)
        for ($i = $start; $i < $end; $i++) {
            if ($args[$i] === '-a') {
                $left = $this->evaluate($args, $commandContext, $start, $i);
                $right = $this->evaluate($args, $commandContext, $i + 1, $end);

                return $left && $right;
            }
        }

        // Unary ! (NOT)
        if ($length >= 2 && $args[$start] === '!') {
            return ! $this->evaluate($args, $commandContext, $start + 1, $end);
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
                '-e' => $this->fileExists($commandContext, $val),
                '-f' => $this->isFile($commandContext, $val),
                '-d' => $this->isDirectory($commandContext, $val),
                '-r' => $this->isReadable($commandContext, $val),
                '-w' => $this->isWritable($commandContext, $val),
                '-x' => $this->isExecutable($commandContext, $val),
                '-s' => $this->isNonEmptyFile($commandContext, $val),
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

    private function fileExists(CommandContext $commandContext, string $path): bool
    {
        return $commandContext->fs->exists($this->resolvePath($commandContext, $path));
    }

    private function isFile(CommandContext $commandContext, string $path): bool
    {
        $resolved = $this->resolvePath($commandContext, $path);

        if (! $commandContext->fs->exists($resolved)) {
            return false;
        }

        try {
            $stat = $commandContext->fs->stat($resolved);

            return $stat->isFile;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function isDirectory(CommandContext $commandContext, string $path): bool
    {
        $resolved = $this->resolvePath($commandContext, $path);

        if (! $commandContext->fs->exists($resolved)) {
            return false;
        }

        try {
            $stat = $commandContext->fs->stat($resolved);

            return $stat->isDirectory;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function isReadable(CommandContext $commandContext, string $path): bool
    {
        $resolved = $this->resolvePath($commandContext, $path);

        if (! $commandContext->fs->exists($resolved)) {
            return false;
        }

        try {
            $stat = $commandContext->fs->stat($resolved);

            return ($stat->mode & 0o444) !== 0;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function isWritable(CommandContext $commandContext, string $path): bool
    {
        $resolved = $this->resolvePath($commandContext, $path);

        if (! $commandContext->fs->exists($resolved)) {
            return false;
        }

        try {
            $stat = $commandContext->fs->stat($resolved);

            return ($stat->mode & 0o222) !== 0;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function isExecutable(CommandContext $commandContext, string $path): bool
    {
        $resolved = $this->resolvePath($commandContext, $path);

        if (! $commandContext->fs->exists($resolved)) {
            return false;
        }

        try {
            $stat = $commandContext->fs->stat($resolved);

            return ($stat->mode & 0o111) !== 0;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function isNonEmptyFile(CommandContext $commandContext, string $path): bool
    {
        $resolved = $this->resolvePath($commandContext, $path);

        if (! $commandContext->fs->exists($resolved)) {
            return false;
        }

        try {
            $stat = $commandContext->fs->stat($resolved);

            return $stat->isFile && $stat->size > 0;
        } catch (RuntimeException) {
            return false;
        }
    }
}
