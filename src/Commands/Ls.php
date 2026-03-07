<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Ls extends AbstractCommand
{
    public function getName(): string
    {
        return 'ls';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        // Handle -1 separately since PHP coerces '1' key to int
        $onePerLine = false;
        $filtered = [];

        foreach ($args as $arg) {
            if ($arg === '-1') {
                $onePerLine = true;
            } else {
                $filtered[] = $arg;
            }
        }

        $parsed = $this->parseFlags($filtered, [
            'l' => false,
            'a' => false,
            'R' => false,
            'd' => false,
        ]);

        $longFormat = (bool) $parsed['flags']['l'];
        $showAll = (bool) $parsed['flags']['a'];
        $recursive = (bool) $parsed['flags']['R'];
        $directoryItself = (bool) $parsed['flags']['d'];
        $paths = $parsed['args'];

        if ($paths === []) {
            $paths = ['.'];
        }

        $output = '';
        $stderr = '';
        $exitCode = 0;
        $multiPath = count($paths) > 1 || $recursive;

        foreach ($paths as $idx => $target) {
            $resolved = $this->resolvePath($commandContext, $target);

            try {
                $stat = $commandContext->fs->stat($resolved);
            } catch (RuntimeException) {
                $stderr .= "ls: cannot access '{$target}': No such file or directory\n";
                $exitCode = 1;

                continue;
            }

            if ($stat->isFile || ($stat->isDirectory && $directoryItself)) {
                if ($longFormat) {
                    $output .= $this->formatLong($commandContext, $resolved, basename($target))."\n";
                } else {
                    $output .= basename($target)."\n";
                }

                continue;
            }

            if ($stat->isDirectory && ! $directoryItself) {
                $output .= $this->listDirectory(
                    $commandContext,
                    $resolved,
                    $target,
                    $longFormat,
                    $showAll,
                    $recursive,
                    $onePerLine,
                    $multiPath,
                    $idx > 0,
                );
            }
        }

        if ($exitCode !== 0) {
            return $this->failure($stderr, $exitCode, $output);
        }

        return $this->success($output);
    }

    private function listDirectory(
        CommandContext $commandContext,
        string $resolved,
        string $displayPath,
        bool $longFormat,
        bool $showAll,
        bool $recursive,
        bool $onePerLine,
        bool $showHeader,
        bool $needsBlankLine,
    ): string {
        $output = '';

        if ($needsBlankLine) {
            $output .= "\n";
        }

        if ($showHeader) {
            $output .= $displayPath.':
';
        }

        try {
            $entries = $commandContext->fs->readdirWithFileTypes($resolved);
        } catch (RuntimeException) {
            return $output;
        }

        if (! $showAll) {
            $entries = array_values(array_filter(
                $entries,
                fn (\BashBox\Filesystem\DirentEntry $direntEntry): bool => ! str_starts_with($direntEntry->name, '.'),
            ));
        }

        if ($longFormat) {
            $output .= 'total '.count($entries)."\n";

            foreach ($entries as $entry) {
                $childPath = $resolved === '/' ? '/'.$entry->name : sprintf('%s/%s', $resolved, $entry->name);
                $output .= $this->formatLong($commandContext, $childPath, $entry->name)."\n";
            }
        } else {
            $names = array_map(fn (\BashBox\Filesystem\DirentEntry $direntEntry): string => $direntEntry->name, $entries);

            if ($onePerLine) {
                foreach ($names as $name) {
                    $output .= $name."\n";
                }
            } else {
                $output .= implode('  ', $names).($names !== [] ? "\n" : '');
            }
        }

        if ($recursive) {
            foreach ($entries as $entry) {
                if ($entry->isDirectory && $entry->name !== '.' && $entry->name !== '..') {
                    $childPath = $resolved === '/' ? '/'.$entry->name : sprintf('%s/%s', $resolved, $entry->name);
                    $childDisplay = $displayPath === '.' ? $entry->name : sprintf('%s/%s', $displayPath, $entry->name);
                    $output .= $this->listDirectory(
                        $commandContext,
                        $childPath,
                        $childDisplay,
                        $longFormat,
                        $showAll,
                        $recursive,
                        $onePerLine,
                        true,
                        true,
                    );
                }
            }
        }

        return $output;
    }

    private function formatLong(CommandContext $commandContext, string $path, string $name): string
    {
        try {
            $stat = $commandContext->fs->stat($path);
        } catch (RuntimeException) {
            return $name;
        }

        $type = $stat->isDirectory ? 'd' : '-';
        $perms = $this->formatPermissions($stat->mode);
        $size = $stat->size;
        $date = date('M j H:i', $stat->mtime);

        return sprintf('%s%s 1 user user %5d %s %s', $type, $perms, $size, $date, $name);
    }

    private function formatPermissions(int $mode): string
    {
        $perms = '';
        $perms .= (($mode & 0400) !== 0) ? 'r' : '-';
        $perms .= (($mode & 0200) !== 0) ? 'w' : '-';
        $perms .= (($mode & 0100) !== 0) ? 'x' : '-';
        $perms .= (($mode & 0040) !== 0) ? 'r' : '-';
        $perms .= (($mode & 0020) !== 0) ? 'w' : '-';
        $perms .= (($mode & 0010) !== 0) ? 'x' : '-';
        $perms .= (($mode & 0004) !== 0) ? 'r' : '-';
        $perms .= (($mode & 0002) !== 0) ? 'w' : '-';
        $perms .= (($mode & 0001) !== 0) ? 'x' : '-';

        return $perms;
    }
}
