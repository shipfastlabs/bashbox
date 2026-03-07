<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Head extends AbstractCommand
{
    public function getName(): string
    {
        return 'head';
    }

    public function execute(array $args, CommandContext $ctx): ExecResult
    {
        $parsed = $this->parseFlags($args, ['n' => '10']);
        $numLines = (int) $parsed['flags']['n'];
        $files = $parsed['args'];

        if ($files === []) {
            $files = ['-'];
        }

        $output = '';
        $stderr = '';
        $multiFile = count($files) > 1;
        $exitCode = 0;

        foreach ($files as $idx => $file) {
            $content = '';

            if ($file === '-') {
                $content = $ctx->stdin;
            } else {
                $path = $this->resolvePath($ctx, $file);

                try {
                    $content = $ctx->fs->readFile($path);
                } catch (RuntimeException) {
                    $stderr .= "head: cannot open '{$file}' for reading: No such file or directory\n";
                    $exitCode = 1;

                    continue;
                }
            }

            if ($multiFile) {
                if ($idx > 0) {
                    $output .= "\n";
                }

                $output .= "==> {$file} <==\n";
            }

            $lines = explode("\n", $content);

            if ($numLines >= count($lines)) {
                $output .= $content;
            } else {
                $selected = array_slice($lines, 0, $numLines);
                $output .= implode("\n", $selected)."\n";
            }
        }

        if ($exitCode !== 0) {
            return $this->failure($stderr, $exitCode, $output);
        }

        return $this->success($output);
    }
}
