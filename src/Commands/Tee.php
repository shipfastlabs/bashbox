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

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->getopt($args, 'a', ['append' => ['a', false]]);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $files] = $parsed;
        $content = $commandContext->stdin;
        $stderr = '';

        foreach ($files as $file) {
            try {
                $this->writeOutputFile($commandContext, $file, $content, isset($flags['a']));
            } catch (RuntimeException $runtimeException) {
                $stderr .= sprintf("tee: %s: %s\n", $file, $this->describeError($runtimeException));
            }
        }

        return $stderr === '' ? $this->success($content) : $this->failure($stderr, 1, $content);
    }
}
