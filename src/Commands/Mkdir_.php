<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Mkdir_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'mkdir';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->parseFlags($args, ['p' => false]);
        $recursive = (bool) $parsed['flags']['p'];
        $dirs = $parsed['args'];

        if ($dirs === []) {
            return $this->failure("mkdir: missing operand\n");
        }

        $stderr = '';
        $exitCode = 0;

        foreach ($dirs as $dir) {
            $path = $this->resolvePath($commandContext, $dir);

            try {
                $commandContext->fs->mkdir($path, ['recursive' => $recursive]);
            } catch (RuntimeException $e) {
                $stderr .= sprintf("mkdir: cannot create directory '%s': %s%s", $dir, $e->getMessage(), PHP_EOL);
                $exitCode = 1;
            }
        }

        if ($exitCode !== 0) {
            return $this->failure($stderr, $exitCode);
        }

        return $this->success();
    }
}
