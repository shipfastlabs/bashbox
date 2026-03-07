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
            'n' => '',
        ]);

        $flags = $parsed['flags'];
        $remaining = $parsed['args'];

        $stdin = $commandContext->stdin;

        $replaceStr = (string) $flags['I'];
        $maxArgs = $flags['n'] !== '' ? (int) $flags['n'] : 0;

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

        $runCommand = function (array $cmdParts) use ($commandContext, &$output, &$stderr, &$exitCode): void {
            $cmdLine = implode(' ', array_map($this->shellQuote(...), $cmdParts));
            $execResult = ($commandContext->exec)($cmdLine);
            $output .= $execResult->stdout;
            $stderr .= $execResult->stderr;

            if ($execResult->exitCode !== 0) {
                $exitCode = $execResult->exitCode;
            }
        };

        if ($replaceStr !== '') {
            // -I mode: run the command once per line, replacing the placeholder
            $lineItems = array_filter(explode("\n", $stdin), fn (string $s): bool => trim($s) !== '');

            foreach ($lineItems as $lineItem) {
                $lineItem = trim($lineItem);
                $cmdParts = [];

                foreach ($remaining as $part) {
                    $cmdParts[] = str_replace($replaceStr, $lineItem, $part);
                }

                $runCommand($cmdParts);
            }
        } elseif ($maxArgs > 0) {
            // -n mode: split items into chunks and run command for each chunk
            foreach (array_chunk($items, $maxArgs) as $chunk) {
                $runCommand(array_merge($remaining, $chunk));
            }
        } else {
            // Default mode: pass all items as arguments to a single command invocation
            $runCommand(array_merge($remaining, $items));
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
