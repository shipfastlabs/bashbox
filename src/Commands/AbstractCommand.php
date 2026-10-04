<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\Exceptions\ExecutionLimitException;
use BashBox\ExecResult;
use BashBox\Interpreter\StdinStream;
use InvalidArgumentException;
use RuntimeException;

abstract class AbstractCommand implements CommandInterface
{
    /** strerror() text for the error codes the filesystems report. */
    private const array ERRORS = [
        'ENOENT' => 'No such file or directory',
        'EEXIST' => 'File exists',
        'EISDIR' => 'Is a directory',
        'ENOTDIR' => 'Not a directory',
        'EACCES' => 'Permission denied',
        'ELOOP' => 'Too many levels of symbolic links',
        'ENOTEMPTY' => 'Directory not empty',
        'EPERM' => 'Operation not permitted',
        'EINVAL' => 'Invalid argument',
        'ENOSPC' => 'No space left on device',
    ];

    protected function success(string $stdout = '', string $stderr = ''): ExecResult
    {
        return new ExecResult(stdout: $stdout, stderr: $stderr, exitCode: 0);
    }

    protected function failure(string $stderr = '', int $exitCode = 1, string $stdout = ''): ExecResult
    {
        return new ExecResult(stdout: $stdout, stderr: $stderr, exitCode: $exitCode);
    }

    /**
     * Parses options with Getopt, returning a usage error as the command's result.
     *
     * @param  list<string>  $args
     * @param  array<string, array{string, ?bool}>  $long
     * @return array{array<string, string>, list<string>}|ExecResult each given option's last argument ('' for a flag), and the operands
     */
    protected function getopt(array $args, string $short, array $long = [], int $status = 1, bool $numbers = false): array|ExecResult
    {
        try {
            [$options, $operands] = Getopt::parse($args, $short, $long, $numbers);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->usageError($invalidArgumentException->getMessage(), $status);
        }

        return [array_column($options, 1, 0), $operands];
    }

    /** GNU's report of a command-line mistake: the message, then a pointer to --help. */
    protected function usageError(string $message, int $status = 1): ExecResult
    {
        return $this->failure(sprintf("%s: %s\nTry '%s --help' for more information.\n", $this->getName(), $message, $this->getName()), $status);
    }

    /**
     * Stops a command before its in-memory output passes the output size limit.
     *
     * @throws ExecutionLimitException
     */
    protected function checkOutputSize(CommandContext $commandContext, int $size): void
    {
        if ($size > $commandContext->limits->maxOutputSize) {
            throw new ExecutionLimitException($this->getName().': output size limit exceeded');
        }
    }

    /**
     * Runs a program the way execvp(3) would: a registered command or an executable file, never a builtin or function.
     *
     * @param  non-empty-list<string>  $argv
     * @param  array<string, string>|null  $env
     * @return ExecResult|array{string, int} the result, or why it couldn't start (strerror text) with GNU's 127/126
     */
    protected function spawn(CommandContext $commandContext, array $argv, ?array $env = null, string|StdinStream|null $stdin = null): ExecResult|array
    {
        $name = $argv[0];

        if (str_contains($name, '/')) {
            $path = $this->resolvePath($commandContext, $name);

            if (! $commandContext->fs->exists($path)) {
                return ['No such file or directory', 127];
            }

            $stat = $commandContext->fs->stat($path);

            if ($stat->isDirectory || ($stat->mode & 0o111) === 0) {
                return ['Permission denied', 126];
            }
        } elseif ($commandContext->registry?->has($name) !== true) {
            return ['No such file or directory', 127];
        }

        // `command` skips functions; the only builtins it can reach run the registered command anyway.
        return ($commandContext->exec)('command '.implode(' ', array_map(fn (string $arg): string => "'".str_replace("'", "'\\''", $arg)."'", $argv)), $env, $stdin);
    }

    protected function resolvePath(CommandContext $commandContext, string $path): string
    {
        return $commandContext->fs->resolvePath($commandContext->cwd, $path);
    }

    /**
     * An operand's contents: stdin for `-`, and no file at all for an empty name, as with open(2).
     *
     * @throws RuntimeException with an errno message, for describeError()
     */
    protected function readOperand(CommandContext $commandContext, string $file): string
    {
        return match ($file) {
            '-' => $commandContext->stdin,
            '' => throw new RuntimeException('ENOENT: no such file or directory'),
            default => $commandContext->fs->readFile($this->resolvePath($commandContext, $file)),
        };
    }

    /**
     * Read every operand ('-' or no operands at all means stdin), continuing past unreadable ones.
     *
     * @param  list<string>  $files
     * @param  string  $errorFormat  sprintf format receiving the operand and the error text
     * @param  bool|null  $alwaysQuote  how the operand is shell-quoted: always, when needed (GNU's quotef) or (null) never
     * @return array{array<int, string>, string} contents keyed by operand index, and the error messages
     */
    protected function readFiles(CommandContext $commandContext, array $files, string $errorFormat, ?bool $alwaysQuote = false): array
    {
        $contents = [];
        $stderr = '';

        foreach ($files ?: ['-'] as $i => $file) {
            try {
                $contents[$i] = $this->readOperand($commandContext, $file);
            } catch (RuntimeException $runtimeException) {
                $stderr .= sprintf($errorFormat, $alwaysQuote === null ? $file : $this->shellEscape($file, $alwaysQuote), $this->describeError($runtimeException));
            }
        }

        return [$contents, $stderr];
    }

    /**
     * Write a command's output file, checking the parent like open(2) does since the filesystem API creates missing parents.
     *
     * @throws RuntimeException with an errno message, for describeError()
     */
    protected function writeOutputFile(CommandContext $commandContext, string $file, string $content, bool $append = false): void
    {
        $fs = $commandContext->fs;
        $path = $this->resolvePath($commandContext, $file);

        match (true) {
            ! $fs->exists(dirname($path)) => throw new RuntimeException('ENOENT: no such file or directory'),
            ! $fs->stat(dirname($path))->isDirectory => throw new RuntimeException('ENOTDIR: not a directory'),
            $append => $fs->appendFile($path, $content),
            default => $fs->writeFile($path, $content),
        };
    }

    /** Turn a filesystem exception ("ENOENT: no such file or directory, open '/x'") into strerror() text. */
    protected function describeError(RuntimeException $runtimeException): string
    {
        return self::ERRORS[strstr($runtimeException->getMessage(), ':', true)] ?? $runtimeException->getMessage();
    }

    /**
     * Expand backslash escapes the way bash's `echo -e` and `printf` do.
     *
     * @param  string  $octal  regex for the digits of an octal escape (the syntax differs between echo and printf)
     * @param  bool  $honorStop  whether `\c` discards the rest of the output (not in a printf format)
     * @return array{string, bool} the expanded string, and whether `\c` cut it short
     */
    protected function expandEscapes(string $str, string $octal, bool $honorStop = true): array
    {
        $stopped = false;

        $expanded = preg_replace_callback(
            '/\\\\(?:(?<octal>'.$octal.')|x(?<hex>[0-9a-fA-F]{1,2})'.($honorStop ? '|(?<stop>c)[\s\S]*' : '').'|(?<char>[\s\S]))/',
            function (array $m) use (&$stopped): string {
                if (($m['octal'] ?? '') !== '') {
                    return chr((int) octdec($m['octal']) & 0xFF);
                }

                if (($m['hex'] ?? '') !== '') {
                    return chr((int) hexdec($m['hex']));
                }

                if (($m['stop'] ?? '') !== '') {
                    $stopped = true;

                    return '';
                }

                return match ($m['char']) {
                    'a' => "\x07",
                    'b' => "\x08",
                    'e', 'E' => "\e",
                    'f' => "\f",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'v' => "\v",
                    '\\' => '\\',
                    default => '\\'.$m['char'],
                };
            },
            $str,
        );

        return [(string) $expanded, $stopped];
    }

    /** Quotes a name like gnulib's shell-escape style: only when needed, unless $always. */
    protected function shellEscape(string $arg, bool $always = false): string
    {
        if ($arg === '') {
            return "''";
        }

        $bare = '[A-Za-z0-9%+,\-.\/:@\]_]';

        if (! $always && preg_match('/^(?:'.$bare.'|(?<=.)[#~{}]|[{}](?=.))+$/s', $arg) === 1) {
            return $arg;
        }

        // A single quote is double-quoted when nothing else in the argument is special inside double quotes.
        if (str_contains($arg, "'") && preg_match('/^(?:'.$bare.'|[ \']|(?<!.)[#~])+$/s', $arg) === 1) {
            return '"'.$arg.'"';
        }

        // Control characters go in $'...' runs between the single-quoted parts.
        $quoted = "'";
        $dollar = false;

        foreach (str_split($arg) as $char) {
            if (ctype_print($char)) {
                $quoted .= ($dollar ? "''" : '').($char === "'" ? "'\\''" : $char);
            } else {
                $escape = array_search($char, ['a' => "\x07", 'b' => "\x08", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v"], true);
                $quoted .= ($dollar ? '' : "'\$'").(is_string($escape) ? '\\'.$escape : sprintf('\\%03o', ord($char)));
            }

            $dollar = ! ctype_print($char);
        }

        return $quoted."'";
    }

    /**
     * @return array{lines: list<string>, trailingNewline: bool}
     */
    protected function splitLines(string $input): array
    {
        $trailingNewline = str_ends_with($input, "\n");
        $lines = $input === '' ? [] : explode("\n", $trailingNewline ? substr($input, 0, -1) : $input);

        return ['lines' => $lines, 'trailingNewline' => $trailingNewline];
    }
}
