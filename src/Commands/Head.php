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

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->parseFlags($args, [
            'n' => '',
            'c' => '',
        ]);

        $flags = $parsed['flags'];
        $files = $parsed['args'];

        // Determine if we're using bytes (-c) or lines (-n)
        $useBytes = $flags['c'] !== '';
        $numBytes = $useBytes ? (int) $flags['c'] : 0;
        $numLines = $flags['n'] !== '' ? (int) $flags['n'] : 10;

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
                $content = $commandContext->stdin;
            } else {
                $path = $this->resolvePath($commandContext, $file);

                try {
                    $content = $commandContext->fs->readFile($path);
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

            if ($useBytes) {
                // Byte mode
                $output .= substr($content, 0, max(0, $numBytes));
            } else {
                // Line mode
                $lines = explode("\n", $content);

                if ($numLines >= count($lines)) {
                    $output .= $content;
                } else {
                    $selected = array_slice($lines, 0, $numLines);
                    $output .= implode("\n", $selected)."\n";
                }
            }
        }

        if ($exitCode !== 0) {
            return $this->failure($stderr, $exitCode, $output);
        }

        return $this->success($output);
    }
}
