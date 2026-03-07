<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Mv extends AbstractCommand
{
    public function getName(): string
    {
        return 'mv';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        if (count($args) < 2) {
            return $this->failure("mv: missing operand\n");
        }

        $dest = array_pop($args);
        $sources = $args;
        $destPath = $this->resolvePath($commandContext, $dest);

        $destIsDir = false;

        try {
            $destStat = $commandContext->fs->stat($destPath);
            $destIsDir = $destStat->isDirectory;
        } catch (RuntimeException) {
            // destination does not exist
        }

        if (count($sources) > 1 && ! $destIsDir) {
            return $this->failure("mv: target '{$dest}' is not a directory\n");
        }

        $stderr = '';
        $exitCode = 0;

        foreach ($sources as $source) {
            $srcPath = $this->resolvePath($commandContext, $source);

            $targetPath = $destPath;

            if ($destIsDir) {
                $basename = basename($source);
                $targetPath = $destPath === '/' ? '/'.$basename : sprintf('%s/%s', $destPath, $basename);
            }

            try {
                $commandContext->fs->mv($srcPath, $targetPath);
            } catch (RuntimeException $e) {
                $stderr .= sprintf("mv: cannot move '%s' to '%s': %s%s", $source, $dest, $e->getMessage(), PHP_EOL);
                $exitCode = 1;
            }
        }

        if ($exitCode !== 0) {
            return $this->failure($stderr, $exitCode);
        }

        return $this->success();
    }
}
