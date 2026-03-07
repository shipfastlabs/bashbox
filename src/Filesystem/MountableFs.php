<?php

declare(strict_types=1);

namespace BashBox\Filesystem;

use RuntimeException;

final class MountableFs implements FileSystemInterface
{
    /**
     * @var array<string, FileSystemInterface>
     */
    private array $mounts = [];

    public function __construct(
        private readonly FileSystemInterface $defaultFs,
    ) {}

    public function mount(string $mountPoint, FileSystemInterface $fs): void
    {
        $mountPoint = $this->normalizePath($mountPoint);
        $this->mounts[$mountPoint] = $fs;
    }

    public function unmount(string $mountPoint): void
    {
        $mountPoint = $this->normalizePath($mountPoint);
        unset($this->mounts[$mountPoint]);
    }

    public function readFile(string $path): string
    {
        [$fs, $innerPath] = $this->resolve($path);

        return $fs->readFile($innerPath);
    }

    public function writeFile(string $path, string $content): void
    {
        [$fs, $innerPath] = $this->resolve($path);
        $fs->writeFile($innerPath, $content);
    }

    public function appendFile(string $path, string $content): void
    {
        [$fs, $innerPath] = $this->resolve($path);
        $fs->appendFile($innerPath, $content);
    }

    public function exists(string $path): bool
    {
        $normalized = $this->normalizePath($path);

        if (isset($this->mounts[$normalized])) {
            return true;
        }

        [$fs, $innerPath] = $this->resolve($path);

        return $fs->exists($innerPath);
    }

    public function stat(string $path): FsStat
    {
        $normalized = $this->normalizePath($path);

        // If the path exactly matches a mount point, stat via the mounted fs root
        if (isset($this->mounts[$normalized])) {
            return $this->mounts[$normalized]->stat('/');
        }

        [$fs, $innerPath] = $this->resolve($path);

        return $fs->stat($innerPath);
    }

    public function lstat(string $path): FsStat
    {
        $normalized = $this->normalizePath($path);

        if (isset($this->mounts[$normalized])) {
            return $this->mounts[$normalized]->lstat('/');
        }

        [$fs, $innerPath] = $this->resolve($path);

        return $fs->lstat($innerPath);
    }

    public function mkdir(string $path, array $options = []): void
    {
        [$fs, $innerPath] = $this->resolve($path);
        $fs->mkdir($innerPath, $options);
    }

    public function readdir(string $path): array
    {
        $entries = $this->readdirWithFileTypes($path);

        return array_map(fn (DirentEntry $e): string => $e->name, $entries);
    }

    public function readdirWithFileTypes(string $path): array
    {
        $normalized = $this->normalizePath($path);
        [$fs, $innerPath] = $this->resolve($path);

        $entries = $fs->readdirWithFileTypes($innerPath);

        // Build a map keyed by name for deduplication
        $entriesMap = [];

        foreach ($entries as $entry) {
            $entriesMap[$entry->name] = $entry;
        }

        // Check if any mount points are direct children of this path
        $prefix = $normalized === '/' ? '/' : $normalized.'/';

        foreach (array_keys($this->mounts) as $mp) {
            if (! str_starts_with($mp, $prefix)) {
                continue;
            }

            $rest = substr($mp, strlen($prefix));
            $slashPos = strpos($rest, '/');
            $name = $slashPos !== false ? substr($rest, 0, $slashPos) : $rest;

            if ($name !== '' && ! isset($entriesMap[$name])) {
                $entriesMap[$name] = new DirentEntry(
                    name: $name,
                    isFile: false,
                    isDirectory: true,
                    isSymbolicLink: false,
                );
            }
        }

        $result = array_values($entriesMap);
        usort($result, fn (DirentEntry $a, DirentEntry $b): int => strcmp($a->name, $b->name));

        return $result;
    }

    public function rm(string $path, array $options = []): void
    {
        [$fs, $innerPath] = $this->resolve($path);
        $fs->rm($innerPath, $options);
    }

    public function cp(string $src, string $dest, array $options = []): void
    {
        [$srcFs, $srcInner] = $this->resolve($src);
        [$destFs, $destInner] = $this->resolve($dest);

        if ($srcFs === $destFs) {
            $srcFs->cp($srcInner, $destInner, $options);

            return;
        }

        // Cross-filesystem copy
        $recursive = $options['recursive'] ?? false;
        $srcStat = $srcFs->stat($srcInner);

        if ($srcStat->isFile) {
            $content = $srcFs->readFile($srcInner);
            $destFs->writeFile($destInner, $content);
        } elseif ($srcStat->isDirectory) {
            if (! $recursive) {
                throw new RuntimeException(sprintf("EISDIR: is a directory, cp '%s'", $src));
            }

            $destFs->mkdir($destInner, ['recursive' => true]);
            $children = $srcFs->readdir($srcInner);

            foreach ($children as $child) {
                $srcChild = $srcInner === '/' ? '/'.$child : sprintf('%s/%s', $srcInner, $child);
                $destChild = $destInner === '/' ? '/'.$child : sprintf('%s/%s', $destInner, $child);
                $srcFullChild = $src === '/' ? '/'.$child : rtrim($src, '/').('/'.$child);
                $destFullChild = $dest === '/' ? '/'.$child : rtrim($dest, '/').('/'.$child);
                $this->cp($srcFullChild, $destFullChild, $options);
            }
        }
    }

    public function mv(string $src, string $dest): void
    {
        $this->cp($src, $dest, ['recursive' => true]);
        $this->rm($src, ['recursive' => true]);
    }

    public function resolvePath(string $base, string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $this->normalizePath($path);
        }

        $combined = $base === '/' ? '/'.$path : sprintf('%s/%s', $base, $path);

        return $this->normalizePath($combined);
    }

    public function getAllPaths(): array
    {
        $allPaths = $this->defaultFs->getAllPaths();

        foreach ($this->mounts as $mp => $fs) {
            $mountedPaths = $fs->getAllPaths();

            foreach ($mountedPaths as $innerPath) {
                $allPaths[] = $innerPath === '/' ? $mp : $mp.$innerPath;
            }
        }

        return array_values(array_unique($allPaths));
    }

    public function chmod(string $path, int $mode): void
    {
        [$fs, $innerPath] = $this->resolve($path);
        $fs->chmod($innerPath, $mode);
    }

    public function symlink(string $target, string $linkPath): void
    {
        [$fs, $innerPath] = $this->resolve($linkPath);
        $fs->symlink($target, $innerPath);
    }

    public function link(string $existingPath, string $newPath): void
    {
        [$existingFs, $existingInner] = $this->resolve($existingPath);
        [$newFs, $newInner] = $this->resolve($newPath);

        if ($existingFs !== $newFs) {
            throw new RuntimeException(sprintf("EXDEV: cross-device link not permitted, link '%s' -> '%s'", $existingPath, $newPath));
        }

        $existingFs->link($existingInner, $newInner);
    }

    public function readlink(string $path): string
    {
        [$fs, $innerPath] = $this->resolve($path);

        return $fs->readlink($innerPath);
    }

    public function realpath(string $path): string
    {
        $normalized = $this->normalizePath($path);
        [$fs, $innerPath] = $this->resolve($path);

        $resolvedInner = $fs->realpath($innerPath);

        // Re-prefix with the mount point
        $mountPoint = $this->findMountPoint($normalized);

        if ($mountPoint === null) {
            return $resolvedInner;
        }

        if ($resolvedInner === '/') {
            return $mountPoint;
        }

        return $mountPoint.$resolvedInner;
    }

    public function utimes(string $path, int $mtime): void
    {
        [$fs, $innerPath] = $this->resolve($path);
        $fs->utimes($innerPath, $mtime);
    }

    /**
     * Resolve a path to the appropriate filesystem and the inner path within that filesystem.
     *
     * Uses longest-prefix matching to find the most specific mount point.
     *
     * @return array{0: FileSystemInterface, 1: string}
     */
    private function resolve(string $path): array
    {
        $normalized = $this->normalizePath($path);
        $bestMount = null;
        $bestLength = 0;

        foreach (array_keys($this->mounts) as $mp) {
            $mpLength = strlen($mp);

            if ($mpLength <= $bestLength) {
                continue;
            }

            if ($normalized === $mp || str_starts_with($normalized, $mp.'/')) {
                $bestMount = $mp;
                $bestLength = $mpLength;
            }
        }

        if ($bestMount === null) {
            return [$this->defaultFs, $normalized];
        }

        $innerPath = substr($normalized, $bestLength);

        if ($innerPath === '') {
            $innerPath = '/';
        }

        return [$this->mounts[$bestMount], $innerPath];
    }

    /**
     * Find the mount point that matches the given normalized path, if any.
     */
    private function findMountPoint(string $normalizedPath): ?string
    {
        $bestMount = null;
        $bestLength = 0;

        foreach (array_keys($this->mounts) as $mp) {
            $mpLength = strlen($mp);

            if ($mpLength <= $bestLength) {
                continue;
            }

            if ($normalizedPath === $mp || str_starts_with($normalizedPath, $mp.'/')) {
                $bestMount = $mp;
                $bestLength = $mpLength;
            }
        }

        return $bestMount;
    }

    private function normalizePath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        $normalized = $path;

        if (str_ends_with($normalized, '/') && $normalized !== '/') {
            $normalized = rtrim($normalized, '/');
        }

        if (! str_starts_with($normalized, '/')) {
            $normalized = '/'.$normalized;
        }

        $parts = array_filter(explode('/', $normalized), fn (string $p): bool => $p !== '' && $p !== '.');
        $resolved = [];

        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $part;
            }
        }

        return '/'.implode('/', $resolved);
    }
}
