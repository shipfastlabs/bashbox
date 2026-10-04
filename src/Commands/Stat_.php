<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use BashBox\Filesystem\FsStat;
use BashBox\Filesystem\UnixFileMode;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** The sandbox user owns every file, with uid and gid 1000 (0 for root), like find; device numbers are 0. */
final class Stat_ extends AbstractCommand
{
    private const array LONG = [
        'dereference' => ['L', false],
        'format' => ['c', true],
        'printf' => ['printf', true],
        'terse' => ['t', false],
    ];

    private const string DEFAULT = "  File: %N\n  Size: %-10s\tBlocks: %-10b IO Block: %-6o %F\nDevice: 0,0\tInode: %-11i Links: %h\n"
        ."Access: (%04a/%10.10A)  Uid: (%5u/%8U)   Gid: (%5g/%8G)\nAccess: %x\nModify: %y\nChange: %z\n Birth: %w\n";

    public function getName(): string
    {
        return 'stat';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        try {
            [$options, $files] = Getopt::parse($args, 'c:Lt', self::LONG);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->usageError($invalidArgumentException->getMessage());
        }

        if ($files === []) {
            return $this->usageError('missing operand');
        }

        $format = null;
        $escapes = false;
        $newline = '';
        $flags = array_column($options, 1, 0);

        // -c and --printf replace each other, and either beats -t
        foreach ($options as [$option, $value]) {
            if ($option === 'c' || $option === 'printf') {
                [$format, $escapes, $newline] = [$value, $option === 'printf', $option === 'c' ? "\n" : ''];
            }
        }

        $format ??= isset($flags['t']) ? "%n %s %b %f %u %g %D %i %h %t %T %X %Y %Z %W %o\n" : null;

        $user = $commandContext->env['USER'] ?? 'root';
        $output = '';
        $stderr = '';

        foreach ($files as $file) {
            $path = $this->resolvePath($commandContext, $file);

            try {
                // An empty name resolves to the working directory here, but names no file for the kernel
                $stat = match (true) {
                    $file === '' => throw new RuntimeException('ENOENT: no such file or directory'),
                    isset($flags['L']) => $commandContext->fs->stat($path),
                    default => $commandContext->fs->lstat($path),
                };
            } catch (RuntimeException $runtimeException) {
                $stderr .= sprintf("stat: cannot stat %s: %s\n", $this->shellEscape($file, true), $this->describeError($runtimeException));

                continue;
            }

            $target = $stat->isSymbolicLink ? $commandContext->fs->readlink($path) : null;
            // the default format names the file unquoted, -c's %N quotes it
            $name = $format === null ? $file.($target === null ? '' : ' -> '.$target) : $this->shellEscape($file, true).($target === null ? '' : ' -> '.$this->shellEscape($target, true));
            // -c's newline is added after the format, so a trailing % stays literal
            $output .= (string) preg_replace_callback(
                '/%([-#+ \'0]*)(\d*)(\.\d*)?(.?)|[^%]+/s',
                fn (array $m): string => isset($m[4]) ? $this->directive($m, $stat, $file, $name, $user) : ($escapes ? $this->expandEscapes($m[0], '[0-7]{1,3}', false)[0] : $m[0]),
                $format ?? self::DEFAULT,
            ).$newline;
        }

        return new ExecResult($output, $stderr, $stderr === '' ? 0 : 1);
    }

    /** @param array<int, string> $m flags, width, precision and conversion of one %-directive */
    private function directive(array $m, FsStat $fsStat, string $file, string $name, string $user): string
    {
        [, $flags, $width, $precision, $conversion] = $m;
        $uid = $user === 'root' ? '0' : '1000';
        $type = match (true) {
            $fsStat->isDirectory => 'd',
            $fsStat->isSymbolicLink => 'l',
            default => '-',
        };
        $time = $this->time($fsStat->mtime);
        $description = match ($type) {
            'd' => 'directory',
            'l' => 'symbolic link',
            default => $fsStat->size === 0 ? 'regular empty file' : 'regular file',
        };

        $value = match ($conversion) {
            '', '%' => '%',
            'a' => (str_contains($flags, '#') ? '0' : '').decoct($fsStat->mode & 07777),
            'A' => $type.UnixFileMode::symbolic($fsStat->mode),
            'b' => (string) ($fsStat->isFile ? (int) ceil($fsStat->size / 1024) * 2 : 0),
            'B' => '512',
            'd', 'D', 't', 'T', 'W' => '0',
            'f' => dechex(['d' => 0o40000, 'l' => 0o120000, '-' => 0o100000][$type] | $fsStat->mode),
            'F' => $description,
            'g', 'u' => $uid,
            'G', 'U' => $user,
            'h' => (string) $fsStat->nlink,
            'i' => (string) $fsStat->ino,
            'n' => $file,
            'N' => $name,
            'o' => '4096',
            's' => (string) $fsStat->size,
            'w' => '-',
            'x', 'y', 'z' => $time,
            'X', 'Y', 'Z' => (string) $fsStat->mtime,
            default => '?',
        };

        $digits = (int) substr($precision, 1);
        $numeric = str_contains('abBdDfghiostTu', $conversion);

        // Like printf, a precision is the minimum digits of a number and the maximum length of a string; a time in seconds takes it as decimals
        $value = match (true) {
            $precision === '' => $value,
            str_contains('XYZW', $conversion) => $value.rtrim('.'.str_repeat('0', $precision === '.' ? 9 : $digits), '.'),
            $numeric => str_pad($value, $digits, '0', STR_PAD_LEFT),
            default => substr($value, 0, $digits),
        };
        $zeros = $numeric && $precision === '' && str_contains($flags, '0') && ! str_contains($flags, '-');

        return str_pad($value, (int) $width, $zeros ? '0' : ' ', str_contains($flags, '-') ? STR_PAD_RIGHT : STR_PAD_LEFT);
    }

    private function time(int $mtime): string
    {
        return new DateTimeImmutable('@'.$mtime)->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s.000000000 O');
    }
}
