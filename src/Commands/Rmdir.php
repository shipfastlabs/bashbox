<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Rmdir extends AbstractCommand
{
    private const array LONG = [
        'ignore-fail-on-non-empty' => ['ignore', false],
        'parents' => ['p', false],
        'verbose' => ['v', false],
    ];

    private string $stdout = '';

    private string $stderr = '';

    public function getName(): string
    {
        return 'rmdir';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->getopt($args, 'pv', self::LONG);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $operands] = $parsed;

        if ($operands === []) {
            return $this->usageError('missing operand');
        }

        $this->stdout = '';
        $this->stderr = '';

        foreach ($operands as $operand) {
            $dir = rtrim($operand, '/') ?: '/';
            $removed = $this->remove($commandContext, $dir, $flags, true);

            while ($removed && isset($flags['p']) && ! in_array($dir = dirname($dir), ['.', '/'], true)) {
                $removed = $this->remove($commandContext, $dir, $flags, false);
            }
        }

        return new ExecResult($this->stdout, $this->stderr, $this->stderr === '' ? 0 : 1);
    }

    /**
     * @param  array<string, string>  $flags
     * @return bool whether the directory is gone
     *
     * @phpstan-impure
     */
    private function remove(CommandContext $commandContext, string $dir, array $flags, bool $operand): bool
    {
        $this->stdout .= isset($flags['v']) ? sprintf("rmdir: removing directory, '%s'\n", $dir) : '';
        $path = $this->resolvePath($commandContext, $dir);

        try {
            if ($commandContext->fs->exists($path) && ! $commandContext->fs->lstat($path)->isDirectory) {
                throw new RuntimeException('ENOTDIR: not a directory');
            }

            $commandContext->fs->rm($path);

            return true;
        } catch (RuntimeException $runtimeException) {
            if (! isset($flags['ignore']) || ! str_starts_with($runtimeException->getMessage(), 'ENOTEMPTY')) {
                $this->stderr .= sprintf("rmdir: failed to remove %s'%s': %s\n", $operand ? '' : 'directory ', $dir, $this->describeError($runtimeException));
            }

            return false;
        }
    }
}
