<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use BashBox\Filesystem\DirentEntry;
use BashBox\Filesystem\FsStat;
use BashBox\Filesystem\UnixFileMode;
use InvalidArgumentException;
use RuntimeException;

/** Output is what GNU ls writes when stdout is not a terminal: one name per line. */
final class Ls extends AbstractCommand
{
    private const array LONG = [
        'all' => ['a', false],
        'almost-all' => ['A', false],
        'dereference' => ['L', false],
        'directory' => ['d', false],
        'recursive' => ['R', false],
    ];

    private string $stderr = '';

    private int $exitCode = 0;

    private bool $dereference = false;

    private CommandContext $commandContext;

    public function getName(): string
    {
        return 'ls';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        try {
            [$parsed, $operands] = Getopt::parse($args, '1AadlLR', self::LONG);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->usageError($invalidArgumentException->getMessage(), 2);
        }

        $flags = array_column($parsed, 1, 0);
        $hidden = 'none';

        // Of -a and -A, the last one given wins.
        foreach ($parsed as [$flag]) {
            $hidden = ['a' => 'all', 'A' => 'almost'][$flag] ?? $hidden;
        }

        // -1 is already the format for output that isn't a terminal.
        $options = [
            'long' => isset($flags['l']),
            'hidden' => $hidden,
            'recursive' => isset($flags['R']) && ! isset($flags['d']),
        ];
        $paths = $operands === [] ? ['.'] : $operands;
        sort($paths, SORT_STRING);

        $this->stderr = '';
        $this->exitCode = 0;
        $this->dereference = isset($flags['L']);
        $this->commandContext = $commandContext;
        $files = [];
        $directories = [];
        // Like GNU, a symlink operand is followed unless -l or -d shows the link itself.
        $follow = ! isset($flags['l']) && ! isset($flags['d']);

        foreach ($paths as $path) {
            $resolved = $this->resolvePath($commandContext, $path);
            $stat = $this->stat($resolved, $this->dereference || $follow) ?? ($this->dereference ? null : $this->stat($resolved, false));

            if (! $stat instanceof FsStat) {
                $this->stderr .= "ls: cannot access '{$path}': No such file or directory\n";
                $this->exitCode = 2;

                continue;
            }

            if ($stat->isDirectory && ! isset($flags['d'])) {
                $directories[$path] = $resolved;
            } else {
                $files[$path] = $resolved;
            }
        }

        // Operands that are files come first, then each directory's listing.
        $output = $this->format($files, $options['long'], false, '');
        $showHeader = count($paths) > 1 || $options['recursive'];

        foreach ($directories as $path => $resolved) {
            $output .= $this->listDirectory($resolved, (string) $path, $options, $showHeader, $output !== '', true);
        }

        return new ExecResult(stdout: $output, stderr: $this->stderr, exitCode: $this->exitCode);
    }

    /**
     * @param  array{long: bool, hidden: string, recursive: bool}  $options
     */
    private function listDirectory(
        string $resolved,
        string $display,
        array $options,
        bool $showHeader,
        bool $needsBlankLine,
        bool $isOperand,
    ): string {
        try {
            $entries = $this->commandContext->fs->readdirWithFileTypes($resolved);
        } catch (RuntimeException) {
            $this->stderr .= "ls: cannot open directory '{$display}': Permission denied\n";
            // Failing on an operand is serious trouble (2), on a subdirectory a minor problem (1).
            $this->exitCode = max($this->exitCode, $isOperand ? 2 : 1);

            return '';
        }

        $output = ($needsBlankLine ? "\n" : '').($showHeader ? $display.":\n" : '');

        if ($options['hidden'] !== 'all') {
            $entries = array_filter($entries, fn (DirentEntry $direntEntry): bool => $options['hidden'] === 'almost' || ! str_starts_with($direntEntry->name, '.'));
        } else {
            array_unshift($entries, new DirentEntry('.', false, true, false), new DirentEntry('..', false, true, false));
        }

        $base = rtrim($resolved, '/').'/';
        $children = [];

        foreach ($entries as $entry) {
            $children[$entry->name] = $base.$entry->name;
        }

        // GNU names a child in messages by joining it to the directory as given, except for ".".
        $output .= $this->format($children, $options['long'], true, match (true) {
            $display === '.' => '',
            str_ends_with($display, '/') => $display,
            default => $display.'/',
        });

        if (! $options['recursive']) {
            return $output;
        }

        foreach ($entries as $entry) {
            $isDirectory = $this->dereference ? $this->stat($base.$entry->name, true)?->isDirectory : $entry->isDirectory;

            if ($isDirectory === true && $entry->name !== '.' && $entry->name !== '..') {
                $childDisplay = rtrim($display, '/').'/'.$entry->name;
                $output .= $this->listDirectory($base.$entry->name, $childDisplay, $options, true, true, false);
            }
        }

        return $output;
    }

    /**
     * @param  array<string, string>  $entries  display name => resolved path
     */
    private function format(array $entries, bool $long, bool $withTotal, string $prefix): string
    {
        if (! $long) {
            return implode('', array_map(fn (int|string $name): string => $name."\n", array_keys($entries)));
        }

        $rows = [];
        $blocks = 0;

        foreach ($entries as $name => $path) {
            $stat = $this->stat($path, $this->dereference);

            if (! $stat instanceof FsStat) {
                // -L on a dangling symlink: GNU still lists it, with nothing known about it.
                $this->stderr .= sprintf("ls: cannot access '%s%s': No such file or directory\n", $prefix, $name);
                $this->exitCode = max($this->exitCode, 1);
                $rows[] = ['l?????????', '?', '?', '?', '?', $name];

                continue;
            }

            $type = $stat->isDirectory ? 'd' : ($stat->isSymbolicLink ? 'l' : '-');
            $suffix = $stat->isSymbolicLink ? ' -> '.$this->commandContext->fs->readlink($path) : '';
            // GNU counts allocated disk blocks; this models a filesystem with 1K blocks, and symlinks stored in the inode.
            $blocks += $stat->isSymbolicLink ? 0 : (int) ceil($stat->size / 1024);
            // Like GNU ls, show the year instead of the time for files older than six months or in the future.
            $recent = $stat->mtime <= time() && $stat->mtime > time() - 15778476;
            $date = date('M', $stat->mtime).sprintf(' %2d ', (int) date('j', $stat->mtime)).($recent ? date('H:i', $stat->mtime) : ' '.date('Y', $stat->mtime));
            // The sandbox user (whoami, $USER) owns every file, in a group of the same name.
            $owner = $this->commandContext->env['USER'] ?? 'root';
            $rows[] = [$type.UnixFileMode::symbolic($stat->mode), (string) $stat->nlink, $owner, (string) $stat->size, $date, $name.$suffix];
        }

        $widths = array_map(fn (int $column): int => max([0, ...array_map(fn (array $row): int => strlen($row[$column]), $rows)]), [1, 2, 3]);
        $output = $withTotal ? sprintf("total %d\n", $blocks) : '';

        foreach ($rows as [$mode, $links, $owner, $size, $date, $name]) {
            $output .= sprintf("%s %{$widths[0]}s %-{$widths[1]}s %-{$widths[1]}s %{$widths[2]}s %12s %s\n", $mode, $links, $owner, $owner, $size, $date, $name);
        }

        return $output;
    }

    private function stat(string $path, bool $follow): ?FsStat
    {
        try {
            return $follow ? $this->commandContext->fs->stat($path) : $this->commandContext->fs->lstat($path);
        } catch (RuntimeException) {
            return null;
        }
    }
}
