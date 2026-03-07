<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

abstract class AbstractCommand implements CommandInterface
{
    protected function success(string $stdout = '', string $stderr = ''): ExecResult
    {
        return new ExecResult(stdout: $stdout, stderr: $stderr, exitCode: 0);
    }

    protected function failure(string $stderr = '', int $exitCode = 1, string $stdout = ''): ExecResult
    {
        return new ExecResult(stdout: $stdout, stderr: $stderr, exitCode: $exitCode);
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|bool>  $flagDefs  flag name => default value (bool for flags, string for options)
     * @return array{flags: array<string, string|bool>, args: list<string>}
     */
    protected function parseFlags(array $args, array $flagDefs): array
    {
        $flags = $flagDefs;
        $remaining = [];
        $i = 0;

        while ($i < count($args)) {
            $arg = $args[$i];

            if ($arg === '--') {
                $remaining = array_merge($remaining, array_slice($args, $i + 1));

                break;
            }

            if (str_starts_with($arg, '-') && $arg !== '-') {
                $flag = ltrim($arg, '-');

                if (isset($flagDefs[$flag])) {
                    if (is_bool($flagDefs[$flag])) {
                        $flags[$flag] = true;
                    } else {
                        $i++;
                        $flags[$flag] = $args[$i] ?? '';
                    }
                } elseif (strlen($flag) >= 2 && isset($flagDefs[$flag[0]]) && ! is_bool($flagDefs[$flag[0]])) {
                    // Handle -dVALUE (e.g., -d: means delimiter is :)
                    $flags[$flag[0]] = substr($flag, 1);
                } else {
                    // Handle combined short flags: -rf => -r -f
                    $handled = true;
                    for ($j = 0; $j < strlen($flag); $j++) {
                        $ch = $flag[$j];
                        if (isset($flagDefs[$ch]) && is_bool($flagDefs[$ch])) {
                            $flags[$ch] = true;
                        } elseif (isset($flagDefs[$ch]) && ! is_bool($flagDefs[$ch])) {
                            // String-valued flag consumes rest of the combined flag
                            $flags[$ch] = substr($flag, $j + 1) ?: ($args[++$i] ?? '');

                            break;
                        } else {
                            $handled = false;

                            break;
                        }
                    }

                    if (! $handled) {
                        $remaining[] = $arg;
                    }
                }
            } else {
                $remaining[] = $arg;
            }

            $i++;
        }

        return ['flags' => $flags, 'args' => $remaining];
    }

    protected function resolvePath(CommandContext $ctx, string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $ctx->fs->resolvePath($ctx->cwd, $path);
    }

    /**
     * @return array{lines: list<string>, trailingNewline: bool}
     */
    protected function splitLines(string $input): array
    {
        $trailingNewline = str_ends_with($input, "\n");
        $lines = explode("\n", $input);
        if ($trailingNewline && $lines[count($lines) - 1] === '') {
            array_pop($lines);
        }

        return ['lines' => $lines, 'trailingNewline' => $trailingNewline];
    }
}
