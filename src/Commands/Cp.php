<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Cp extends AbstractCommand
{
    public function getName(): string
    {
        return 'cp';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->parseFlags($args, [
            'r' => false,
            'R' => false,
            'p' => false,
        ]);

        $recursive = (bool) $parsed['flags']['r'] || (bool) $parsed['flags']['R'];
        $preserve = (bool) $parsed['flags']['p'];
        $operands = $parsed['args'];

        if (count($operands) < 2) {
            return $this->failure("cp: missing operand\n");
        }

        $dest = array_pop($operands);
        $destPath = $this->resolvePath($commandContext, $dest);

        $destIsDir = false;

        try {
            $destStat = $commandContext->fs->stat($destPath);
            $destIsDir = $destStat->isDirectory;
        } catch (RuntimeException) {
            // destination does not exist
        }

        if (count($operands) > 1 && ! $destIsDir) {
            return $this->failure("cp: target '{$dest}' is not a directory\n");
        }

        $stderr = '';
        $exitCode = 0;

        foreach ($operands as $operand) {
            $srcPath = $this->resolvePath($commandContext, $operand);

            $targetPath = $destPath;

            if ($destIsDir) {
                $basename = basename($operand);
                $targetPath = $destPath === '/' ? '/'.$basename : sprintf('%s/%s', $destPath, $basename);
            }

            try {
                $commandContext->fs->cp($srcPath, $targetPath, ['recursive' => $recursive, 'preserve' => $preserve]);
            } catch (RuntimeException $e) {
                $stderr .= sprintf('cp: %s%s', $e->getMessage(), PHP_EOL);
                $exitCode = 1;
            }
        }

        if ($exitCode !== 0) {
            return $this->failure($stderr, $exitCode);
        }

        return $this->success();
    }
}
