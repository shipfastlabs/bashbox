<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use BashBox\Filesystem\FileSystemInterface;
use BashBox\Filesystem\FsStat;
use RuntimeException;

/** GNU `ln`: -s, -f, -n, -T, -t DIR, -v, -r. Hard links are made to a symlink itself (-P, the Linux default). */
final class Ln extends AbstractCommand
{
    private const array LONG_OPTIONS = [
        'symbolic' => 's',
        'force' => 'f',
        'no-dereference' => 'n',
        'no-target-directory' => 'T',
        'target-directory' => 't',
        'verbose' => 'v',
        'relative' => 'r',
    ];

    private const string USAGE = "Try 'ln --help' for more information.\n";

    public function getName(): string
    {
        return 'ln';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $flags = array_fill_keys(['s', 'f', 'n', 'T', 'v', 'r'], false);
        $targetDir = null;
        $operands = [];
        $counter = count($args);

        for ($i = 0; $i < $counter; $i++) {
            $arg = $args[$i];

            if ($arg === '--') {
                array_push($operands, ...array_slice($args, $i + 1));

                break;
            }

            if (str_starts_with($arg, '--')) {
                [$name, $value] = explode('=', substr($arg, 2), 2) + [1 => null];
                $option = self::LONG_OPTIONS[$name] ?? null;

                if ($option === null) {
                    return $this->failure(sprintf("ln: unrecognized option '%s'\n", $arg).self::USAGE);
                }

                if ($option !== 't') {
                    $flags[$option] = true;

                    continue;
                }

                $targetDir = $value ?? $args[++$i] ?? null;

                if ($targetDir === null) {
                    return $this->failure("ln: option '--target-directory' requires an argument\n".self::USAGE);
                }

                continue;
            }

            if ($arg === '-' || ! str_starts_with($arg, '-')) {
                $operands[] = $arg;

                continue;
            }

            for ($j = 1, $length = strlen($arg); $j < $length; $j++) {
                $option = $arg[$j];

                if ($option === 't') {
                    $targetDir = $j + 1 < $length ? substr($arg, $j + 1) : $args[++$i] ?? null;

                    if ($targetDir === null) {
                        return $this->failure("ln: option requires an argument -- 't'\n".self::USAGE);
                    }

                    break;
                }

                if (! isset($flags[$option])) {
                    return $this->failure(sprintf("ln: invalid option -- '%s'\n", $option).self::USAGE);
                }

                $flags[$option] = true;
            }
        }

        $error = match (true) {
            $operands === [] => "missing file operand\n".self::USAGE,
            $flags['r'] && ! $flags['s'] => "cannot do --relative without --symbolic\n",
            $flags['T'] && $targetDir !== null => "cannot combine --target-directory and --no-target-directory\n",
            $flags['T'] && count($operands) === 1 => sprintf("missing destination file operand after '%s'\n", $operands[0]).self::USAGE,
            $flags['T'] && count($operands) > 2 => sprintf("extra operand '%s'\n", $operands[2]).self::USAGE,
            default => null,
        };

        if ($error !== null) {
            return $this->failure('ln: '.$error);
        }

        $fs = $commandContext->fs;

        if ($targetDir !== null) {
            $dirPath = $this->resolvePath($commandContext, $targetDir);

            if (! $this->isDirectory($fs, $dirPath, ! $flags['n'])) {
                return $this->failure($fs->exists($dirPath)
                    ? sprintf("ln: target '%s' is not a directory\n", $targetDir)
                    : sprintf("ln: failed to access '%s': No such file or directory\n", $targetDir));
            }
        } elseif (! $flags['T'] && count($operands) > 1) {
            // The last operand is a directory to link into; with two operands it may instead be the link name.
            $last = $operands[count($operands) - 1];
            $lastPath = $this->resolvePath($commandContext, $last);
            $isDirectory = $this->isDirectory($fs, $lastPath, ! $flags['n']);

            if (! $isDirectory && count($operands) > 2) {
                return $this->failure(sprintf("ln: target '%s': %s\n", $last, $fs->exists($lastPath) ? 'Not a directory' : 'No such file or directory'));
            }

            if ($isDirectory) {
                $targetDir = array_pop($operands);
            }
        }

        $links = match (true) {
            $targetDir !== null => array_map(fn (string $target): array => [$target, $this->join($targetDir, basename($target))], $operands),
            count($operands) === 1 => [[$operands[0], './'.basename($operands[0])]],
            default => [$operands],
        };

        $stdout = '';
        $stderr = '';

        foreach ($links as [$target, $linkName]) {
            $value = $flags['r'] ? $this->relativeTarget($fs, $this->resolvePath($commandContext, $target), $this->resolvePath($commandContext, $linkName)) : $target;
            $error = $this->link($commandContext, $flags, $target, $value, $linkName);

            if ($error !== null) {
                $stderr .= 'ln: '.$error."\n";
            } elseif ($flags['v']) {
                $stdout .= sprintf("'%s' %s '%s'\n", $linkName, $flags['s'] ? '->' : '=>', $value);
            }
        }

        return $stderr === '' ? $this->success($stdout) : $this->failure($stderr, 1, $stdout);
    }

    /**
     * @param  array<string, bool>  $flags
     * @param  string  $value  what a symlink will contain
     * @return string|null the error, if the link was not made
     */
    private function link(CommandContext $commandContext, array $flags, string $target, string $value, string $linkName): ?string
    {
        $fs = $commandContext->fs;
        $targetPath = $this->resolvePath($commandContext, $target);
        $linkPath = $this->resolvePath($commandContext, $linkName);
        $parent = dirname($linkPath);

        if (! $flags['s']) {
            try {
                if ($fs->lstat($targetPath)->isDirectory) {
                    return $target.': hard link not allowed for directory';
                }
            } catch (RuntimeException $runtimeException) {
                return sprintf("failed to access '%s': %s", $target, $this->describeError($runtimeException));
            }
        }

        $existing = $this->lstat($fs, $linkPath);

        if ($flags['f'] && $existing instanceof FsStat) {
            // The same directory entry; for -s, which stats the target, a symlink named twice is not the same file.
            if ($this->canonicalName($fs, $targetPath) === $this->canonicalName($fs, $linkPath) && (! $flags['s'] || ! $existing->isSymbolicLink)) {
                return sprintf("'%s' and '%s' are the same file", $target, $linkName);
            }

            if ($existing->isDirectory) {
                return $linkName.': cannot overwrite directory';
            }

            try {
                $fs->rm($linkPath);
            } catch (RuntimeException $runtimeException) {
                return sprintf("cannot remove '%s': %s", $linkName, $this->describeError($runtimeException));
            }
        }

        $kind = $flags['s'] ? 'symbolic link' : 'hard link';

        // The filesystems would create missing parents; ln does not.
        if (! $this->isDirectory($fs, $parent, true)) {
            return sprintf("failed to create %s '%s': %s", $kind, $linkName, $fs->exists($parent) ? 'Not a directory' : 'No such file or directory');
        }

        try {
            $flags['s'] ? $fs->symlink($value, $linkPath) : $fs->link($targetPath, $linkPath);
        } catch (RuntimeException $runtimeException) {
            return sprintf("failed to create %s '%s': %s", $kind, $linkName, $this->describeError($runtimeException));
        }

        return null;
    }

    private function isDirectory(FileSystemInterface $fileSystem, string $path, bool $followLink): bool
    {
        try {
            return ($followLink ? $fileSystem->stat($path) : $fileSystem->lstat($path))->isDirectory;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function lstat(FileSystemInterface $fileSystem, string $path): ?FsStat
    {
        try {
            return $fileSystem->lstat($path);
        } catch (RuntimeException) {
            return null;
        }
    }

    /** Resolve every symlink that exists, keeping a missing tail as written (realpath -m). */
    private function canonical(FileSystemInterface $fileSystem, string $path): string
    {
        try {
            return $fileSystem->realpath($path);
        } catch (RuntimeException) {
            return $path === '/' ? '/' : $this->join($this->canonical($fileSystem, dirname($path)), basename($path));
        }
    }

    /** The directory entry a path names: its parent resolved, its last component not followed. */
    private function canonicalName(FileSystemInterface $fileSystem, string $path): string
    {
        return $this->join($this->canonical($fileSystem, dirname($path)), basename($path));
    }

    /** -r: the target relative to the link's directory, both with symlinks resolved. */
    private function relativeTarget(FileSystemInterface $fileSystem, string $targetPath, string $linkPath): string
    {
        $from = $this->split($this->canonical($fileSystem, dirname($linkPath)));
        $to = $this->split($this->canonical($fileSystem, $targetPath));
        $common = 0;

        while (isset($from[$common], $to[$common]) && $from[$common] === $to[$common]) {
            $common++;
        }

        $parts = [...array_fill(0, count($from) - $common, '..'), ...array_slice($to, $common)];

        return $parts === [] ? '.' : implode('/', $parts);
    }

    /**
     * @return list<string>
     */
    private function split(string $path): array
    {
        return array_values(array_filter(explode('/', $path), fn (string $part): bool => $part !== ''));
    }

    private function join(string $dir, string $name): string
    {
        return rtrim($dir, '/').'/'.$name;
    }
}
