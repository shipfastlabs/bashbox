<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Cat extends AbstractCommand
{
    public function getName(): string
    {
        return 'cat';
    }

    public function execute(array $args, CommandContext $ctx): ExecResult
    {
        $numberLines = false;
        $files = [];

        foreach ($args as $arg) {
            if ($arg === '-n') {
                $numberLines = true;
            } elseif ($arg === '-') {
                $files[] = '-';
            } elseif (! str_starts_with($arg, '-')) {
                $files[] = $arg;
            }
        }

        if ($files === []) {
            $files = ['-'];
        }

        $output = '';
        $lineNum = 1;

        foreach ($files as $file) {
            $content = '';

            if ($file === '-') {
                $content = $ctx->stdin;
            } else {
                $path = $this->resolvePath($ctx, $file);

                try {
                    $content = $ctx->fs->readFile($path);
                } catch (RuntimeException) {
                    return $this->failure("cat: {$file}: No such file or directory\n");
                }
            }

            if ($numberLines) {
                $lines = explode("\n", $content);
                $last = array_pop($lines);

                foreach ($lines as $line) {
                    $output .= sprintf("%6d\t%s\n", $lineNum++, $line);
                }

                if ($last !== '') {
                    $output .= sprintf("%6d\t%s", $lineNum++, $last);
                }
            } else {
                $output .= $content;
            }
        }

        return $this->success($output);
    }
}
