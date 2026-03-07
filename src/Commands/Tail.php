<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Tail extends AbstractCommand
{
    public function getName(): string
    {
        return 'tail';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
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
                $content = $commandContext->stdin;
            } else {
                $path = $this->resolvePath($commandContext, $file);

                try {
                    $content = $commandContext->fs->readFile($path);
                } catch (RuntimeException) {
                    $stderr .= "tail: cannot open '{$file}' for reading: No such file or directory\n";
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

            // If content ends with a newline, the last element is an empty string
            $endsWithNewline = $content !== '' && str_ends_with($content, "\n");

            if ($endsWithNewline) {
                array_pop($lines);
            }

            if ($numLines >= count($lines)) {
                $output .= $content;
            } else {
                $selected = array_slice($lines, -$numLines);
                $output .= implode("\n", $selected).($endsWithNewline ? "\n" : '');
            }
        }

        if ($exitCode !== 0) {
            return $this->failure($stderr, $exitCode, $output);
        }

        return $this->success($output);
    }
}
