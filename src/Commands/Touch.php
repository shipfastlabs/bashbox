<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Touch extends AbstractCommand
{
    public function getName(): string
    {
        return 'touch';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->getopt($args, 'c', ['no-create' => ['c', false]]);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $operands] = $parsed;

        if ($operands === []) {
            return $this->usageError('missing file operand');
        }

        $fs = $commandContext->fs;
        $stderr = '';

        foreach ($operands as $operand) {
            $path = $this->resolvePath($commandContext, $operand);

            try {
                if ($fs->exists($path)) {
                    $fs->utimes($path, time());
                } elseif (! $fs->exists(dirname($path))) {
                    throw new RuntimeException('ENOENT: no such file or directory');
                } elseif (! isset($flags['c'])) {
                    $fs->writeFile($path, '');
                }
            } catch (RuntimeException $runtimeException) {
                $stderr .= sprintf("touch: %s '%s': %s\n", $fs->exists($path) ? 'setting times of' : 'cannot touch', $operand, $this->describeError($runtimeException));
            }
        }

        return $stderr === '' ? $this->success() : $this->failure($stderr);
    }
}
