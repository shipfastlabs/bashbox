<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

/** `tac` without -r: GNU's regex mode reorders records unpredictably, so it is left out. */
final class Tac extends AbstractCommand
{
    public function getName(): string
    {
        return 'tac';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->getopt($args, 'bs:', ['before' => ['b', false], 'separator' => ['s', true]]);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $files] = $parsed;
        // An empty separator means NUL.
        $separator = ($flags['s'] ?? "\n") ?: "\0";
        $output = '';
        $stderr = '';

        foreach ($files ?: ['-'] as $file) {
            try {
                $content = $this->readOperand($commandContext, $file);
            } catch (RuntimeException $runtimeException) {
                $error = $this->describeError($runtimeException);
                $stderr .= $error === 'Is a directory'
                    ? sprintf("tac: %s: read error: %s\n", $this->shellEscape($file), $error)
                    : sprintf("tac: failed to open %s for reading: %s\n", $this->shellEscape($file, true), $error);

                continue;
            }

            $pieces = explode($separator, $content);

            if (isset($flags['b'])) {
                $records = [array_shift($pieces), ...array_map(fn (string $piece): string => $separator.$piece, $pieces)];
            } else {
                // The text after the last separator has none of its own and comes out first.
                $last = array_pop($pieces);
                $records = [...array_map(fn (string $piece): string => $piece.$separator, $pieces), $last];
            }

            $output .= implode('', array_reverse($records));
        }

        return $stderr === '' ? $this->success($output) : $this->failure($stderr, 1, $output);
    }
}
