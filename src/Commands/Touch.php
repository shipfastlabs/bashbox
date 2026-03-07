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
        if ($args === []) {
            return $this->failure("touch: missing file operand\n");
        }

        $stderr = '';
        $exitCode = 0;

        foreach ($args as $arg) {
            $path = $this->resolvePath($commandContext, $arg);

            try {
                if ($commandContext->fs->exists($path)) {
                    $commandContext->fs->utimes($path, time());
                } else {
                    $commandContext->fs->writeFile($path, '');
                }
            } catch (RuntimeException $e) {
                $stderr .= sprintf("touch: cannot touch '%s': %s%s", $arg, $e->getMessage(), PHP_EOL);
                $exitCode = 1;
            }
        }

        if ($exitCode !== 0) {
            return $this->failure($stderr, $exitCode);
        }

        return $this->success();
    }
}
