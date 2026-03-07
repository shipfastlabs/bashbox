<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Xargs extends AbstractCommand
{
    public function getName(): string
    {
        return 'xargs';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->parseFlags($args, [
            'I' => '',
        ]);

        $flags = $parsed['flags'];
        $remaining = $parsed['args'];

        $stdin = $commandContext->stdin;

        $replaceStr = (string) $flags['I'];

        // Split stdin into items
        $items = $this->splitInput($stdin);

        if ($items === []) {
            // With no input items and a command, run nothing
            return $this->success('');
        }

        if ($remaining === []) {
            // Default command is echo
            $remaining = ['echo'];
        }

        $output = '';
        $stderr = '';
        $exitCode = 0;

        if ($replaceStr !== '') {
            // -I mode: run the command once per line, replacing the placeholder
            $lineItems = array_filter(explode("\n", $stdin), fn (string $s): bool => trim($s) !== '');

            foreach ($lineItems as $lineItem) {
                $lineItem = trim($lineItem);
                $cmdParts = [];

                foreach ($remaining as $part) {
                    $cmdParts[] = str_replace($replaceStr, $lineItem, $part);
                }

                $cmdLine = implode(' ', array_map($this->shellQuote(...), $cmdParts));
                $result = ($commandContext->exec)($cmdLine);
                $output .= $result->stdout;
                $stderr .= $result->stderr;

                if ($result->exitCode !== 0) {
                    $exitCode = $result->exitCode;
                }
            }
        } else {
            // Default mode: pass all items as arguments to a single command invocation
            $cmdParts = array_merge($remaining, $items);
            $cmdLine = implode(' ', array_map($this->shellQuote(...), $cmdParts));
            $result = ($commandContext->exec)($cmdLine);
            $output .= $result->stdout;
            $stderr .= $result->stderr;
            $exitCode = $result->exitCode;
        }

        return new ExecResult(stdout: $output, stderr: $stderr, exitCode: $exitCode);
    }

    /**
     * Split stdin into items by whitespace/newlines.
     *
     * @return list<string>
     */
    private function splitInput(string $input): array
    {
        $input = trim($input);

        if ($input === '') {
            return [];
        }

        /** @var list<string> $items */
        $items = preg_split('/\s+/', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $items;
    }

    private function shellQuote(string $arg): string
    {
        // If the argument contains spaces or special chars, quote it
        if ($arg === '' || preg_match('/[\\s"\'\\\\|&;<>()$`!]/', $arg)) {
            return "'".str_replace("'", "'\\''", $arg)."'";
        }

        return $arg;
    }
}
