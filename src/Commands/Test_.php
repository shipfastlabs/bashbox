<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

/** POSIX test: the argument count decides the parse up to four arguments, longer expressions use test.c's -o -a ! ( ) grammar. */
final class Test_ extends AbstractCommand
{
    private const array UNARY = ['-z', '-n', '-e', '-a', '-f', '-d', '-r', '-w', '-x', '-s', '-h', '-L', '-u', '-g', '-k', '-O', '-G', '-b', '-c', '-p', '-S', '-t'];

    private const array BINARY = ['=', '==', '!=', '<', '>', '-eq', '-ne', '-lt', '-le', '-gt', '-ge', '-nt', '-ot', '-ef'];

    /** @var list<string> */
    private array $args = [];

    private int $pos = 0;

    private CommandContext $commandContext;

    /**
     * @param  'test'|'['  $name  the `[` form requires a closing `]` argument
     */
    public function __construct(private readonly string $name = 'test') {}

    public function getName(): string
    {
        return $this->name;
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        if ($this->name === '[' && array_pop($args) !== ']') {
            return $this->failure("bash: [: missing `]'\n", 2);
        }

        $this->args = $args;
        $this->pos = 0;
        $this->commandContext = $commandContext;

        try {
            $result = match (count($args)) {
                0 => false,
                1, 2, 3 => $this->countBased(0, count($args)),
                4 => match (true) {
                    $args[0] === '!' => ! $this->countBased(1, 3),
                    $args[0] === '(' && $args[3] === ')' => $this->countBased(1, 2),
                    default => $this->expression(),
                },
                default => $this->expression(),
            };
        } catch (RuntimeException $runtimeException) {
            return $this->failure(sprintf("bash: %s: %s\n", $this->name, $runtimeException->getMessage()), 2);
        }

        return $result ? $this->success() : $this->failure();
    }

    /** POSIX rules for 1-3 arguments starting at $start. */
    private function countBased(int $start, int $count): bool
    {
        $a = $this->args[$start];

        return match ($count) {
            1 => $a !== '',
            2 => match (true) {
                $a === '!' => $this->args[$start + 1] === '',
                in_array($a, self::UNARY, true) => $this->unary($a, $this->args[$start + 1]),
                default => throw new RuntimeException($a.': unary operator expected'),
            },
            default => match (true) {
                in_array($this->args[$start + 1], self::BINARY, true) => $this->binary($a, $this->args[$start + 1], $this->args[$start + 2]),
                $this->args[$start + 1] === '-a' => $a !== '' && $this->args[$start + 2] !== '',
                $this->args[$start + 1] === '-o' => $a !== '' || $this->args[$start + 2] !== '',
                $a === '!' => ! $this->countBased($start + 1, 2),
                $a === '(' && $this->args[$start + 2] === ')' => $this->args[$start + 1] !== '',
                default => throw new RuntimeException($this->args[$start + 1].': binary operator expected'),
            },
        };
    }

    private function expression(): bool
    {
        $result = $this->orExpr();

        if ($this->pos < count($this->args)) {
            throw new RuntimeException('too many arguments');
        }

        return $result;
    }

    private function orExpr(): bool
    {
        $result = $this->andExpr();

        while (($this->args[$this->pos] ?? null) === '-o') {
            $this->pos++;
            // Evaluate before combining so the right side is always consumed.
            $right = $this->andExpr();
            $result = $result || $right;
        }

        return $result;
    }

    private function andExpr(): bool
    {
        $result = $this->term();

        while (($this->args[$this->pos] ?? null) === '-a') {
            $this->pos++;
            $right = $this->term();
            $result = $result && $right;
        }

        return $result;
    }

    private function term(): bool
    {
        if ($this->pos >= count($this->args)) {
            throw new RuntimeException('argument expected');
        }

        $arg = $this->args[$this->pos++];

        if ($arg === '!') {
            return ! $this->term();
        }

        if ($arg === '(') {
            $result = $this->orExpr();

            if (($this->args[$this->pos++] ?? null) !== ')') {
                throw new RuntimeException("`)' expected");
            }

            return $result;
        }

        $next = $this->args[$this->pos] ?? null;

        if (in_array($arg, self::UNARY, true) && $next !== null) {
            $this->pos++;

            return $this->unary($arg, $next);
        }

        if ($next !== null && in_array($next, self::BINARY, true) && isset($this->args[$this->pos + 1])) {
            $this->pos += 2;

            return $this->binary($arg, $next, $this->args[$this->pos - 1]);
        }

        return $arg !== '';
    }

    private function unary(string $op, string $value): bool
    {
        if ($op === '-z' || $op === '-n') {
            return ($value === '') === ($op === '-z');
        }

        // The sandbox has no devices, pipes, sockets or terminals.
        if (in_array($op, ['-b', '-c', '-p', '-S', '-t'], true)) {
            return false;
        }

        $fs = $this->commandContext->fs;
        $path = $this->resolvePath($this->commandContext, $value);

        try {
            $stat = in_array($op, ['-h', '-L'], true) ? $fs->lstat($path) : $fs->stat($path);
        } catch (RuntimeException) {
            return false;
        }

        return match ($op) {
            '-f' => $stat->isFile,
            '-d' => $stat->isDirectory,
            '-h', '-L' => $stat->isSymbolicLink,
            '-r' => ($stat->mode & 0o444) !== 0,
            '-w' => ($stat->mode & 0o222) !== 0,
            '-x' => ($stat->mode & 0o111) !== 0,
            '-s' => $stat->size > 0,
            '-u' => ($stat->mode & 0o4000) !== 0,
            '-g' => ($stat->mode & 0o2000) !== 0,
            '-k' => ($stat->mode & 0o1000) !== 0,
            default => true, // -e, -a, and -O/-G: the sandbox user owns every file
        };
    }

    private function binary(string $left, string $op, string $right): bool
    {
        return match ($op) {
            '=', '==' => $left === $right,
            '!=' => $left !== $right,
            '<' => strcmp($left, $right) < 0,
            '>' => strcmp($left, $right) > 0,
            // A missing file counts as older than any existing one.
            '-nt' => ($this->stat($left)->mtime ?? PHP_INT_MIN) > ($this->stat($right)->mtime ?? PHP_INT_MIN),
            '-ot' => ($this->stat($left)->mtime ?? PHP_INT_MIN) < ($this->stat($right)->mtime ?? PHP_INT_MIN),
            '-ef' => ($real = $this->realpath($left)) !== null && $real === $this->realpath($right),
            '-eq' => $this->integer($left) === $this->integer($right),
            '-ne' => $this->integer($left) !== $this->integer($right),
            '-lt' => $this->integer($left) < $this->integer($right),
            '-le' => $this->integer($left) <= $this->integer($right),
            '-gt' => $this->integer($left) > $this->integer($right),
            default => $this->integer($left) >= $this->integer($right),
        };
    }

    private function stat(string $file): ?\BashBox\Filesystem\FsStat
    {
        try {
            return $this->commandContext->fs->stat($this->resolvePath($this->commandContext, $file));
        } catch (RuntimeException) {
            return null;
        }
    }

    private function realpath(string $file): ?string
    {
        try {
            return $this->commandContext->fs->realpath($this->resolvePath($this->commandContext, $file));
        } catch (RuntimeException) {
            return null;
        }
    }

    private function integer(string $value): int
    {
        if (preg_match('/^\s*[+-]?\d+\s*$/', $value) !== 1) {
            throw new RuntimeException($value.': integer expected');
        }

        return (int) trim($value);
    }
}
