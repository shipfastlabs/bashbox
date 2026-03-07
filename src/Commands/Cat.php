<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\Commands\Input\InputContent;
use BashBox\ExecResult;
use RuntimeException;

final class Cat extends AbstractCommand
{
    public function getName(): string
    {
        return 'cat';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
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

        try {
            $reader = $this->createInputReader();
            $input = $reader->read($files, $commandContext);
        } catch (RuntimeException $runtimeException) {
            preg_match('/No such file/', $runtimeException->getMessage(), $matches);
            $filename = $files[0];

            return $this->failure("cat: {$filename}: No such file or directory\n");
        }

        $output = $this->formatOutput($input, $numberLines);

        return $this->success($output);
    }

    private function formatOutput(InputContent $inputContent, bool $numberLines): string
    {
        if (! $numberLines) {
            return $inputContent->content;
        }

        $output = '';
        $lineNum = 1;

        foreach ($inputContent->files as $fileData) {
            $content = $fileData['content'];
            $lines = explode("\n", $content);
            $last = array_pop($lines);

            foreach ($lines as $line) {
                $output .= sprintf("%6d\t%s\n", $lineNum++, $line);
            }

            if ($last !== '') {
                $output .= sprintf("%6d\t%s", $lineNum++, $last);
            }
        }

        return $output;
    }
}
