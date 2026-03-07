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

    public function execute(array $args, CommandContext $ctx): ExecResult
    {
        $parsed = $this->parseFlags($args, [
            'r' => false,
            'R' => false,
            'f' => false,
        ]);

        $recursive = (bool) $parsed['flags']['r'] || (bool) $parsed['flags']['R'];
        $force = (bool) $parsed['flags']['f'];
        $targets = $parsed['args'];

        if ($targets === [] && ! $force) {
            return $this->failure("rm: missing operand\n");
        }

        if ($targets === []) {
            return $this->success();
        }

        $stderr = '';
        $exitCode = 0;

        foreach ($targets as $target) {
            $path = $this->resolvePath($ctx, $target);

            try {
                $ctx->fs->rm($path, ['recursive' => $recursive, 'force' => $force]);
            } catch (RuntimeException $e) {
                if (! $force) {
                    $stderr .= sprintf("rm: cannot remove '%s': %s%s", $target, $e->getMessage(), PHP_EOL);
                    $exitCode = 1;
                }
            }
        }

        if ($exitCode !== 0) {
            return $this->failure($stderr, $exitCode);
        }

        return $this->success();
    }
}
