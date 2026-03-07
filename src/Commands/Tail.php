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
        $parsed = $this->parseFlags($args, [
            'n' => '',
            'c' => '',
        ]);

        $flags = $parsed['flags'];
        $files = $parsed['args'];

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
            try {
                $reader = $this->createInputReader();
                $input = $reader->read([$file], $commandContext);
            } catch (RuntimeException) {
                $stderr .= "tail: cannot open '{$file}' for reading: No such file or directory\n";
                $exitCode = 1;

                continue;
            }

            if ($multiFile) {
                if ($idx > 0) {
                    $output .= "\n";
                }

                $output .= "==> {$file} <==\n";
            }

            $output .= $this->formatContent($input->content, $useBytes, $numBytes, $numLines);
        }

        if ($exitCode !== 0) {
            return $this->failure($stderr, $exitCode, $output);
        }

        return $this->success($output);
    }

    private function formatContent(string $content, bool $useBytes, int $numBytes, int $numLines): string
    {
        if ($useBytes) {
            if ($numBytes <= 0) {
                return '';
            }

            $contentLength = strlen($content);

            if ($numBytes >= $contentLength) {
                return $content;
            }

            return substr($content, -$numBytes);
        }

        $lines = explode("\n", $content);

        $endsWithNewline = $content !== '' && str_ends_with($content, "\n");

        if ($endsWithNewline) {
            array_pop($lines);
        }

        if ($numLines >= count($lines)) {
            return $content;
        }

        $selected = array_slice($lines, -$numLines);

        return implode("\n", $selected).($endsWithNewline ? "\n" : '');
    }
}
