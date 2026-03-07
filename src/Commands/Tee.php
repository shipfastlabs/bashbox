<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Tee extends AbstractCommand
{
    public function getName(): string
    {
        return 'tee';
    }

    public function execute(array $args, CommandContext $ctx): ExecResult
    {
        $parsed = $this->parseFlags($args, ['a' => false]);
        $append = (bool) $parsed['flags']['a'];
        $files = $parsed['args'];

        $content = $ctx->stdin;
        $stderr = '';
        $exitCode = 0;

        foreach ($files as $file) {
            $path = $this->resolvePath($ctx, $file);

            try {
                if ($append) {
                    $ctx->fs->appendFile($path, $content);
                } else {
                    $ctx->fs->writeFile($path, $content);
                }
            } catch (RuntimeException) {
                $stderr .= "tee: {$file}: No such file or directory\n";
                $exitCode = 1;
            }
        }

        if ($exitCode !== 0) {
            return $this->failure($stderr, $exitCode, $content);
        }

        return $this->success($content);
    }
}
