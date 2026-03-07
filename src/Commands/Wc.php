<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Wc extends AbstractCommand
{
    public function getName(): string
    {
        return 'wc';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->parseFlags($args, [
            'l' => false,
            'w' => false,
            'c' => false,
        ]);

        $flags = $parsed['flags'];
        $files = $parsed['args'];

        $showLines = (bool) $flags['l'];
        $showWords = (bool) $flags['w'];
        $showBytes = (bool) $flags['c'];

        // If no flags given, show all three
        if (! $showLines && ! $showWords && ! $showBytes) {
            $showLines = true;
            $showWords = true;
            $showBytes = true;
        }

        if ($files === []) {
            $files = ['-'];
        }

        $output = '';
        $totalLines = 0;
        $totalWords = 0;
        $totalBytes = 0;
        $multiFile = count($files) > 1;

        foreach ($files as $file) {
            if ($file === '-') {
                $content = $commandContext->stdin;
            } else {
                $path = $this->resolvePath($commandContext, $file);

                try {
                    $content = $commandContext->fs->readFile($path);
                } catch (RuntimeException) {
                    $output .= "wc: {$file}: No such file or directory\n";

                    continue;
                }
            }

            $lines = $content !== '' ? substr_count($content, "\n") : 0;
            $words = $content !== '' ? count(preg_split('/\s+/', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: []) : 0;
            $bytes = strlen($content);

            $totalLines += $lines;
            $totalWords += $words;
            $totalBytes += $bytes;

            $parts = [];

            if ($showLines) {
                $parts[] = sprintf('%8d', $lines);
            }

            if ($showWords) {
                $parts[] = sprintf('%8d', $words);
            }

            if ($showBytes) {
                $parts[] = sprintf('%8d', $bytes);
            }

            $label = $file === '-' ? '' : ' '.$file;
            $output .= implode('', $parts).$label."\n";
        }

        if ($multiFile) {
            $parts = [];

            if ($showLines) {
                $parts[] = sprintf('%8d', $totalLines);
            }

            if ($showWords) {
                $parts[] = sprintf('%8d', $totalWords);
            }

            if ($showBytes) {
                $parts[] = sprintf('%8d', $totalBytes);
            }

            $output .= implode('', $parts).' total'."\n";
        }

        return $this->success($output);
    }
}
