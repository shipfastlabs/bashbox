<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Rm extends AbstractCommand
{
    public function getName(): string
    {
        return 'rm';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->getopt($args, 'fRr', ['force' => ['f', false], 'recursive' => ['r', false]]);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $targets] = $parsed;
        $recursive = isset($flags['r']) || isset($flags['R']);
        $force = isset($flags['f']);

        if ($targets === [] && ! $force) {
            return $this->usageError('missing operand');
        }

        $stderr = '';

        foreach ($targets as $target) {
            $path = $this->resolvePath($commandContext, $target);

            if (! $commandContext->fs->exists($path)) {
                $stderr .= $force ? '' : "rm: cannot remove '{$target}': No such file or directory\n";
            } elseif (! $recursive && $commandContext->fs->stat($path)->isDirectory) {
                $stderr .= "rm: cannot remove '{$target}': Is a directory\n";
            } else {
                try {
                    $commandContext->fs->rm($path, ['recursive' => true]);
                } catch (RuntimeException $runtimeException) {
                    $stderr .= sprintf("rm: cannot remove '%s': %s\n", $target, $this->describeError($runtimeException));
                }
            }
        }

        return $stderr === '' ? $this->success() : $this->failure($stderr);
    }
}
