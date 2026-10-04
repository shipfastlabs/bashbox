<?php

declare(strict_types=1);

namespace BashBox\Filesystem;

use RuntimeException;

/** Reads a real directory through a contained ReadWriteFs and keeps every change in an in-memory copy-on-write layer, so the disk is never modified. */
final class OverlayFs implements FileSystemInterface
{
    private readonly ReadWriteFs $readWriteFs;

    private readonly InMemoryFs $inMemoryFs;

    /**
     * Paths removed in the overlay, whose disk entries stay hidden even after the path is recreated in memory.
     *
     * @var array<string, true>
     */
    private array $deletedPaths = [];

    /** @param  DiskQuota  $diskQuota  caps the in-memory layer, copied-up files included */
    public function __construct(string $rootDir, private readonly bool $denySymlinks = true, DiskQuota $diskQuota = new DiskQuota)
    {
        $this->readWriteFs = new ReadWriteFs($rootDir, ! $denySymlinks);
        $this->inMemoryFs = new InMemoryFs([], $diskQuota);
    }

    public function readFile(string $path): string
    {
        $path = $this->resolve($path, 'open');

        return $this->layerFor($path, 'open')->readFile($path);
    }

    public function writeFile(string $path, string $content): void
    {
        $path = $this->resolve($path, 'open');
        $this->copyUp($path);
        $this->inMemoryFs->writeFile($path, $content);
    }

    public function createExclusive(string $path, bool $directory = false): void
    {
        $path = $this->resolve($path, $directory ? 'mkdir' : 'open', false);
        // Copying up whatever is already there makes the in-memory layer report EEXIST for it.
        $this->copyUp($path);
        $this->inMemoryFs->createExclusive($path, $directory);
    }

    public function appendFile(string $path, string $content): void
    {
        $path = $this->resolve($path, 'open');
        $this->copyUp($path);
        $this->inMemoryFs->appendFile($path, $content);
    }

    public function exists(string $path): bool
    {
        try {
            return $this->entry($this->resolve($path, 'access')) instanceof FsStat;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function stat(string $path): FsStat
    {
        $path = $this->resolve($path, 'stat');

        return $this->layerFor($path, 'stat')->stat($path);
    }

    public function lstat(string $path): FsStat
    {
        $path = $this->resolve($path, 'lstat', false);

        return $this->layerFor($path, 'lstat')->lstat($path);
    }

    public function mkdir(string $path, array $options = []): void
    {
        if (($options['recursive'] ?? false) && $this->isDirectory($path)) {
            return;
        }

        $path = $this->resolve($path, 'mkdir', false);
        $this->copyUp($path);
        $this->inMemoryFs->mkdir($path, $options);
    }

    public function readdir(string $path): array
    {
        return array_map(fn (DirentEntry $direntEntry): string => $direntEntry->name, $this->readdirWithFileTypes($path));
    }

    public function readdirWithFileTypes(string $path): array
    {
        $path = $this->resolve($path, 'scandir');
        $inUpper = $this->inUpper($path);
        $entries = [];

        try {
            $this->assertNotDeleted($path, 'scandir');

            foreach ($this->readWriteFs->readdirWithFileTypes($path) as $direntEntry) {
                if (! isset($this->deletedPaths[rtrim($path, '/').'/'.$direntEntry->name])) {
                    $entries[$direntEntry->name] = $direntEntry;
                }
            }
        } catch (RuntimeException $runtimeException) {
            if (! $inUpper) {
                throw $runtimeException;
            }
        }

        if ($inUpper) {
            foreach ($this->inMemoryFs->readdirWithFileTypes($path) as $direntEntry) {
                $entries[$direntEntry->name] = $direntEntry;
            }
        }

        ksort($entries, SORT_STRING);

        return array_values($entries);
    }

    public function rm(string $path, array $options = []): void
    {
        try {
            $path = $this->resolve($path, 'rm', false);
            $stat = $this->layerFor($path, 'rm')->lstat($path);
        } catch (RuntimeException $runtimeException) {
            if ($options['force'] ?? false) {
                return;
            }

            throw $runtimeException;
        }

        if ($path === '/') {
            throw new RuntimeException("EPERM: operation not permitted, rm '/'");
        }

        if ($stat->isDirectory && ! ($options['recursive'] ?? false) && $this->readdir($path) !== []) {
            throw new RuntimeException(sprintf("ENOTEMPTY: directory not empty, rm '%s'", $path));
        }

        if ($this->inUpper($path)) {
            $this->inMemoryFs->rm($path, ['recursive' => true]);
        }

        $this->deletedPaths[$path] = true;
    }

    public function cp(string $src, string $dest, array $options = []): void
    {
        $src = $this->resolve($src, 'cp');
        $dest = $this->resolve($dest, 'cp');
        $stat = $this->stat($src);

        if (! $stat->isDirectory) {
            $this->writeFile($dest, $this->readFile($src));

            if ($options['preserve'] ?? false) {
                $this->inMemoryFs->chmod($dest, $stat->mode);
                $this->inMemoryFs->utimes($dest, $stat->mtime);
            }

            return;
        }

        if (! ($options['recursive'] ?? false)) {
            throw new RuntimeException(sprintf("EISDIR: is a directory, cp '%s'", $src));
        }

        if (str_starts_with($dest.'/', $src.'/')) {
            throw new RuntimeException(sprintf("EINVAL: cannot copy a directory into itself, cp '%s'", $src));
        }

        $this->mkdir($dest, ['recursive' => true]);

        foreach ($this->readdir($src) as $child) {
            $this->cp(rtrim($src, '/').'/'.$child, rtrim($dest, '/').'/'.$child, $options);
        }
    }

    /** rename(2) in memory: both trees are copied up whole and their disk versions hidden afterwards. */
    public function mv(string $src, string $dest): void
    {
        $src = $this->resolve($src, 'rename', false);
        $dest = $this->resolve($dest, 'rename', false);
        $this->copyUpTree($src);
        $this->copyUpTree($dest);
        $this->inMemoryFs->mv($src, $dest);
        $this->deletedPaths[$src] = true;
        $this->deletedPaths[$dest] = true;
    }

    public function resolvePath(string $base, string $path): string
    {
        return VirtualPath::resolve($base, $path);
    }

    public function getAllPaths(): array
    {
        $visible = array_filter($this->readWriteFs->getAllPaths(), fn (string $path): bool => ! $this->isDeleted($path));
        $paths = array_values(array_unique([...$visible, ...$this->inMemoryFs->getAllPaths()]));
        sort($paths);

        return $paths;
    }

    public function chmod(string $path, int $mode): void
    {
        $path = $this->resolve($path, 'chmod');
        $this->copyUp($path);
        $this->inMemoryFs->chmod($path, $mode);
    }

    public function symlink(string $target, string $linkPath): void
    {
        if ($this->denySymlinks) {
            throw new RuntimeException(sprintf("EPERM: symlinks are denied, symlink '%s'", $linkPath));
        }

        $linkPath = $this->resolve($linkPath, 'symlink', false);
        $this->copyUp($linkPath);
        $this->inMemoryFs->symlink($target, $linkPath);
    }

    public function link(string $existingPath, string $newPath): void
    {
        $existingPath = $this->resolve($existingPath, 'link', false);
        $newPath = $this->resolve($newPath, 'link', false);
        $this->copyUp($existingPath);
        $this->copyUp($newPath);
        $this->inMemoryFs->link($existingPath, $newPath);
    }

    public function readlink(string $path): string
    {
        $path = $this->resolve($path, 'readlink', false);

        return $this->layerFor($path, 'readlink')->readlink($path);
    }

    public function realpath(string $path): string
    {
        $resolved = $this->resolve($path, 'realpath');

        if (! $this->entry($resolved) instanceof FsStat) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, realpath '%s'", $path));
        }

        return $resolved;
    }

    public function utimes(string $path, int $mtime): void
    {
        $path = $this->resolve($path, 'utimes');
        $this->copyUp($path);
        $this->inMemoryFs->utimes($path, $mtime);
    }

    /** Resolve symlinks of both layers in every component (the last only when $followLast), within the virtual root. */
    private function resolve(string $path, string $operation, bool $followLast = true): string
    {
        $pending = explode('/', VirtualPath::normalize($path));
        $resolved = [];
        $hops = 0;

        while ($pending !== []) {
            $part = array_shift($pending);

            if ($part === '') {
                continue;
            }

            if ($part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($resolved);

                continue;
            }

            $next = '/'.implode('/', [...$resolved, $part]);
            $entry = $this->entry($next);

            if ($pending !== [] && $entry instanceof FsStat && ! $entry->isDirectory && ! $entry->isSymbolicLink) {
                throw new RuntimeException(sprintf("ENOTDIR: not a directory, %s '%s'", $operation, $path));
            }

            if (! $entry instanceof FsStat || ! $entry->isSymbolicLink || (! $followLast && $pending === [])) {
                $resolved[] = $part;

                continue;
            }

            if (++$hops > VirtualPath::MAX_SYMLINKS) {
                throw new RuntimeException(sprintf("ELOOP: too many levels of symbolic links, %s '%s'", $operation, $path));
            }

            if ($this->inUpper($next)) {
                $target = $this->inMemoryFs->readlink($next);
            } else {
                $this->assertDiskLinkContained($next, $operation, $path);
                $target = $this->readWriteFs->readlink($next);
            }

            if (str_starts_with($target, '/')) {
                $resolved = [];
            }

            $pending = [...explode('/', $target), ...$pending];
        }

        return '/'.implode('/', $resolved);
    }

    /** A disk symlink leaving the root (an absolute host path, or ".." above it) is denied like ReadWriteFs does, not reinterpreted inside it. */
    private function assertDiskLinkContained(string $link, string $operation, string $path): void
    {
        try {
            $this->readWriteFs->realpath($link);
        } catch (RuntimeException $runtimeException) {
            if (str_starts_with($runtimeException->getMessage(), 'EACCES')) {
                throw new RuntimeException(sprintf("EACCES: path traversal denied, %s '%s'", $operation, $path), 0, $runtimeException);
            }
        }
    }

    /** The merged lstat of a path whose ancestors are symlink-free, or null when nothing is there. */
    private function entry(string $path): ?FsStat
    {
        if ($this->inUpper($path)) {
            return $this->inMemoryFs->lstat($path);
        }

        if ($this->isDeleted($path)) {
            return null;
        }

        try {
            return $this->readWriteFs->lstat($path);
        } catch (RuntimeException $runtimeException) {
            // A denied disk symlink stays an error rather than looking absent.
            if (str_starts_with($runtimeException->getMessage(), 'ENOENT')) {
                return null;
            }

            throw $runtimeException;
        }
    }

    private function isDirectory(string $path): bool
    {
        try {
            return $this->stat($path)->isDirectory;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** The layer that owns a path: the in-memory copy when there is one, otherwise the disk. */
    private function layerFor(string $path, string $operation): FileSystemInterface
    {
        if ($this->inUpper($path)) {
            return $this->inMemoryFs;
        }

        $this->assertNotDeleted($path, $operation);

        return $this->readWriteFs;
    }

    private function assertNotDeleted(string $path, string $operation): void
    {
        if ($this->isDeleted($path)) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, %s '%s'", $operation, $path));
        }
    }

    private function inUpper(string $path): bool
    {
        try {
            $this->inMemoryFs->lstat($path);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function isDeleted(string $path): bool
    {
        while (! isset($this->deletedPaths[$path])) {
            if ($path === '/') {
                return false;
            }

            $path = dirname($path);
        }

        return true;
    }

    /** Copy a resolved disk entry and its ancestors into memory before it changes, as a new inode like Linux overlayfs, so the change sees what is on disk. */
    private function copyUp(string $path): void
    {
        if ($path !== '/') {
            $this->copyUp(dirname($path));
        }

        $stat = $this->inUpper($path) ? null : $this->entry($path);

        if (! $stat instanceof FsStat) {
            return;
        }

        if ($stat->isSymbolicLink) {
            $this->inMemoryFs->symlink($this->readWriteFs->readlink($path), $path);

            return;
        }

        if ($stat->isDirectory) {
            $this->inMemoryFs->mkdir($path);
        } else {
            $this->inMemoryFs->writeFile($path, $this->readWriteFs->readFile($path));
        }

        $this->inMemoryFs->chmod($path, $stat->mode);
        $this->inMemoryFs->utimes($path, $stat->mtime);
    }

    /** Copy up an entry and, for a directory, everything below it. */
    private function copyUpTree(string $path): void
    {
        $this->copyUp($path);

        if ($this->inUpper($path) && $this->inMemoryFs->lstat($path)->isDirectory) {
            foreach ($this->readdir($path) as $child) {
                $this->copyUpTree(rtrim($path, '/').'/'.$child);
            }
        }
    }
}
