<?php

declare(strict_types=1);

namespace BashBox\Filesystem;

use RuntimeException;

final class OverlayFs implements FileSystemInterface
{
    private readonly InMemoryFs $inMemoryFs;

    /**
     * Tracks paths that have been explicitly deleted.
     * These must not "show through" from the real filesystem.
     *
     * @var array<string, true>
     */
    private array $deletedPaths = [];

    private readonly string $rootDir;

    public function __construct(
        string $rootDir,
        private readonly bool $denySymlinks = true,
    ) {
        $realRoot = realpath($rootDir);

        if ($realRoot === false || ! is_dir($rootDir)) {
            throw new RuntimeException(sprintf("OverlayFs root directory does not exist: '%s'", $rootDir));
        }

        $this->rootDir = $realRoot;
        $this->inMemoryFs = new InMemoryFs;
    }

    // ---------------------------------------------------------------
    //  FileSystemInterface
    // ---------------------------------------------------------------

    public function readFile(string $path): string
    {
        $this->validatePath($path, 'open');
        $normalized = $this->normalizePath($path);
        $this->assertContained($normalized, 'open');

        // COW layer first
        if ($this->inMemoryFs->exists($normalized)) {
            return $this->inMemoryFs->readFile($normalized);
        }

        // Deleted in overlay -> gone
        if ($this->isDeleted($normalized)) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, open '%s'", $path));
        }

        // Fall back to real filesystem
        $realPath = $this->toRealPath($normalized);
        $this->guardSymlink($realPath, 'open', $path);

        if (! is_file($realPath)) {
            if (is_dir($realPath)) {
                throw new RuntimeException(sprintf("EISDIR: illegal operation on a directory, read '%s'", $path));
            }

            throw new RuntimeException(sprintf("ENOENT: no such file or directory, open '%s'", $path));
        }

        $content = file_get_contents($realPath);

        if ($content === false) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, open '%s'", $path));
        }

        return $content;
    }

    public function writeFile(string $path, string $content): void
    {
        $this->validatePath($path, 'write');
        $normalized = $this->normalizePath($path);
        $this->assertContained($normalized, 'write');

        $this->ensureCowParentDirs($normalized);
        $this->inMemoryFs->writeFile($normalized, $content);
        $this->undelete($normalized);
    }

    public function appendFile(string $path, string $content): void
    {
        $this->validatePath($path, 'append');
        $normalized = $this->normalizePath($path);
        $this->assertContained($normalized, 'append');

        if ($this->inMemoryFs->exists($normalized)) {
            $this->inMemoryFs->appendFile($normalized, $content);

            return;
        }

        // If the file exists on the real FS and is not deleted, pull it in first
        if (! $this->isDeleted($normalized)) {
            $realPath = $this->toRealPath($normalized);

            if (is_file($realPath)) {
                $this->guardSymlink($realPath, 'append', $path);
                $existing = file_get_contents($realPath);

                if ($existing === false) {
                    $existing = '';
                }

                $this->ensureCowParentDirs($normalized);
                $this->inMemoryFs->writeFile($normalized, $existing.$content);
                $this->undelete($normalized);

                return;
            }
        }

        // Otherwise treat as a fresh write
        $this->ensureCowParentDirs($normalized);
        $this->inMemoryFs->writeFile($normalized, $content);
        $this->undelete($normalized);
    }

    public function exists(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }

        try {
            $normalized = $this->normalizePath($path);

            if (! $this->isContained($normalized)) {
                return false;
            }
        } catch (RuntimeException) {
            return false;
        }

        if ($this->inMemoryFs->exists($normalized)) {
            return true;
        }

        if ($this->isDeleted($normalized)) {
            return false;
        }

        $realPath = $this->toRealPath($normalized);

        if ($this->denySymlinks && is_link($realPath)) {
            return false;
        }

        return file_exists($realPath);
    }

    public function stat(string $path): FsStat
    {
        $this->validatePath($path, 'stat');
        $normalized = $this->normalizePath($path);
        $this->assertContained($normalized, 'stat');

        if ($this->inMemoryFs->exists($normalized)) {
            return $this->inMemoryFs->stat($normalized);
        }

        if ($this->isDeleted($normalized)) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, stat '%s'", $path));
        }

        $realPath = $this->toRealPath($normalized);
        $this->guardSymlink($realPath, 'stat', $path);

        if (! file_exists($realPath)) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, stat '%s'", $path));
        }

        return $this->statRealPath($realPath);
    }

    public function lstat(string $path): FsStat
    {
        $this->validatePath($path, 'lstat');
        $normalized = $this->normalizePath($path);
        $this->assertContained($normalized, 'lstat');

        if ($this->inMemoryFs->exists($normalized)) {
            return $this->inMemoryFs->lstat($normalized);
        }

        if ($this->isDeleted($normalized)) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, lstat '%s'", $path));
        }

        $realPath = $this->toRealPath($normalized);

        if (is_link($realPath)) {
            if ($this->denySymlinks) {
                throw new RuntimeException(sprintf("EPERM: symlinks are denied, lstat '%s'", $path));
            }

            $target = readlink($realPath);

            return new FsStat(
                isFile: false,
                isDirectory: false,
                isSymbolicLink: true,
                mode: 0777,
                size: $target !== false ? strlen($target) : 0,
                mtime: filemtime($realPath) ?: time(),
            );
        }

        if (! file_exists($realPath)) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, lstat '%s'", $path));
        }

        return $this->statRealPath($realPath);
    }

    public function mkdir(string $path, array $options = []): void
    {
        $this->validatePath($path, 'mkdir');
        $normalized = $this->normalizePath($path);
        $this->assertContained($normalized, 'mkdir');

        $this->ensureCowParentDirs($normalized);
        $this->inMemoryFs->mkdir($normalized, $options);
        $this->undelete($normalized);
    }

    public function readdir(string $path): array
    {
        $entries = $this->readdirWithFileTypes($path);

        return array_map(fn (DirentEntry $direntEntry): string => $direntEntry->name, $entries);
    }

    public function readdirWithFileTypes(string $path): array
    {
        $this->validatePath($path, 'scandir');
        $normalized = $this->normalizePath($path);
        $this->assertContained($normalized, 'scandir');

        // The directory must exist somewhere (COW or real FS)
        $inCow = $this->inMemoryFs->exists($normalized);
        $realPath = $this->toRealPath($normalized);
        $onReal = ! $this->isDeleted($normalized) && is_dir($realPath);

        if (! $inCow && ! $onReal) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, scandir '%s'", $path));
        }

        /** @var array<string, DirentEntry> $entriesMap */
        $entriesMap = [];

        // 1. Pull entries from real FS
        if ($onReal) {
            $handle = opendir($realPath);

            if ($handle !== false) {
                while (($entry = readdir($handle)) !== false) {
                    if ($entry === '.') {
                        continue;
                    }

                    if ($entry === '..') {
                        continue;
                    }

                    $childNormalized = $normalized === '/' ? '/'.$entry : sprintf('%s/%s', $normalized, $entry);

                    if ($this->isDeleted($childNormalized)) {
                        continue;
                    }

                    $childReal = $realPath.DIRECTORY_SEPARATOR.$entry;

                    if ($this->denySymlinks && is_link($childReal)) {
                        continue;
                    }

                    $entriesMap[$entry] = new DirentEntry(
                        name: $entry,
                        isFile: is_file($childReal),
                        isDirectory: is_dir($childReal),
                        isSymbolicLink: is_link($childReal),
                    );
                }

                closedir($handle);
            }
        }

        // 2. Overlay COW entries (they win)
        if ($inCow) {
            try {
                $cowEntries = $this->inMemoryFs->readdirWithFileTypes($normalized);

                foreach ($cowEntries as $cowEntry) {
                    $entriesMap[$cowEntry->name] = $cowEntry;
                }
            } catch (RuntimeException) {
                // COW path exists but is not a directory — ignore
            }
        }

        $entries = array_values($entriesMap);
        usort($entries, fn (DirentEntry $a, DirentEntry $b): int => strcmp($a->name, $b->name));

        return $entries;
    }

    public function rm(string $path, array $options = []): void
    {
        $this->validatePath($path, 'rm');
        $normalized = $this->normalizePath($path);
        $this->assertContained($normalized, 'rm');
        $force = $options['force'] ?? false;
        $recursive = $options['recursive'] ?? false;

        $existsInCow = $this->inMemoryFs->exists($normalized);
        $realPath = $this->toRealPath($normalized);
        $existsOnReal = ! $this->isDeleted($normalized) && file_exists($realPath);

        if (! $existsInCow && ! $existsOnReal) {
            if ($force) {
                return;
            }

            throw new RuntimeException(sprintf("ENOENT: no such file or directory, rm '%s'", $path));
        }

        // If it is a directory, handle children recursively
        $isDir = false;

        if ($existsInCow) {
            try {
                $stat = $this->inMemoryFs->stat($normalized);
                $isDir = $stat->isDirectory;
            } catch (RuntimeException) {
                // not in cow
            }
        }

        if (! $isDir && $existsOnReal && is_dir($realPath)) {
            $isDir = true;
        }

        if ($isDir) {
            if (! $recursive) {
                // Check if directory is non-empty
                $children = $this->readdir($normalized);

                if ($children !== []) {
                    throw new RuntimeException(sprintf("ENOTEMPTY: directory not empty, rm '%s'", $path));
                }
            } else {
                $children = $this->readdir($normalized);

                foreach ($children as $child) {
                    $childPath = $normalized === '/' ? '/'.$child : sprintf('%s/%s', $normalized, $child);
                    $this->rm($childPath, $options);
                }
            }
        }

        // Remove from COW if present
        if ($existsInCow) {
            $this->inMemoryFs->rm($normalized, ['force' => true, 'recursive' => $recursive]);
        }

        // Mark as deleted so it doesn't show through from real FS
        $this->markDeleted($normalized);
    }

    public function cp(string $src, string $dest, array $options = []): void
    {
        $this->validatePath($src, 'cp');
        $this->validatePath($dest, 'cp');
        $srcNorm = $this->normalizePath($src);
        $destNorm = $this->normalizePath($dest);
        $this->assertContained($srcNorm, 'cp');
        $this->assertContained($destNorm, 'cp');
        $recursive = $options['recursive'] ?? false;

        // Determine source type
        $fsStat = $this->stat($srcNorm);

        if ($fsStat->isFile) {
            $content = $this->readFile($srcNorm);
            $this->writeFile($destNorm, $content);
        } elseif ($fsStat->isDirectory) {
            if (! $recursive) {
                throw new RuntimeException(sprintf("EISDIR: is a directory, cp '%s'", $src));
            }

            $this->mkdir($destNorm, ['recursive' => true]);
            $children = $this->readdir($srcNorm);

            foreach ($children as $child) {
                $srcChild = $srcNorm === '/' ? '/'.$child : sprintf('%s/%s', $srcNorm, $child);
                $destChild = $destNorm === '/' ? '/'.$child : sprintf('%s/%s', $destNorm, $child);
                $this->cp($srcChild, $destChild, $options);
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
        $paths = [];

        // Collect real FS paths (not deleted)
        $this->collectRealPaths($this->rootDir, '/', $paths);

        // Overlay COW paths
        foreach ($this->inMemoryFs->getAllPaths() as $allPath) {
            if (! in_array($allPath, $paths, true)) {
                $paths[] = $allPath;
            }
        }

        sort($paths);

        return $paths;
    }

    public function chmod(string $path, int $mode): void
    {
        $this->validatePath($path, 'chmod');
        $normalized = $this->normalizePath($path);
        $this->assertContained($normalized, 'chmod');

        // Pull into COW if only on real FS
        $this->pullIntoCow($normalized, 'chmod');

        $this->inMemoryFs->chmod($normalized, $mode);
    }

    public function symlink(string $target, string $linkPath): void
    {
        $this->validatePath($linkPath, 'symlink');
        $normalized = $this->normalizePath($linkPath);
        $this->assertContained($normalized, 'symlink');

        if ($this->denySymlinks) {
            throw new RuntimeException(sprintf("EPERM: symlinks are denied, symlink '%s'", $linkPath));
        }

        $this->ensureCowParentDirs($normalized);
        $this->inMemoryFs->symlink($target, $normalized);
        $this->undelete($normalized);
    }

    public function link(string $existingPath, string $newPath): void
    {
        $this->validatePath($existingPath, 'link');
        $this->validatePath($newPath, 'link');
        $existingNorm = $this->normalizePath($existingPath);
        $newNorm = $this->normalizePath($newPath);
        $this->assertContained($existingNorm, 'link');
        $this->assertContained($newNorm, 'link');

        // Pull existing into COW if needed
        $this->pullIntoCow($existingNorm, 'link');

        $this->ensureCowParentDirs($newNorm);
        $this->inMemoryFs->link($existingNorm, $newNorm);
        $this->undelete($newNorm);
    }

    public function readlink(string $path): string
    {
        $this->validatePath($path, 'readlink');
        $normalized = $this->normalizePath($path);
        $this->assertContained($normalized, 'readlink');

        if ($this->inMemoryFs->exists($normalized)) {
            return $this->inMemoryFs->readlink($normalized);
        }

        if ($this->isDeleted($normalized)) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, readlink '%s'", $path));
        }

        $realPath = $this->toRealPath($normalized);

        if (! is_link($realPath)) {
            if (! file_exists($realPath)) {
                throw new RuntimeException(sprintf("ENOENT: no such file or directory, readlink '%s'", $path));
            }

            throw new RuntimeException(sprintf("EINVAL: invalid argument, readlink '%s'", $path));
        }

        if ($this->denySymlinks) {
            throw new RuntimeException(sprintf("EPERM: symlinks are denied, readlink '%s'", $path));
        }

        $target = readlink($realPath);

        if ($target === false) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, readlink '%s'", $path));
        }

        return $target;
    }

    public function realpath(string $path): string
    {
        $this->validatePath($path, 'realpath');
        $normalized = $this->normalizePath($path);
        $this->assertContained($normalized, 'realpath');

        if (! $this->exists($normalized)) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, realpath '%s'", $path));
        }

        return $normalized;
    }

    public function utimes(string $path, int $mtime): void
    {
        $this->validatePath($path, 'utimes');
        $normalized = $this->normalizePath($path);
        $this->assertContained($normalized, 'utimes');

        $this->pullIntoCow($normalized, 'utimes');

        $this->inMemoryFs->utimes($normalized, $mtime);
    }

    // ---------------------------------------------------------------
    //  Private helpers
    // ---------------------------------------------------------------

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

    private function dirname(string $path): string
    {
        $normalized = $this->normalizePath($path);

        if ($normalized === '/') {
            return '/';
        }

        $lastSlash = strrpos($normalized, '/');

        if ($lastSlash === false || $lastSlash === 0) {
            return '/';
        }

        return substr($normalized, 0, $lastSlash);
    }

    private function validatePath(string $path, string $operation): void
    {
        if (str_contains($path, "\0")) {
            throw new RuntimeException(sprintf("ENOENT: path contains null byte, %s '%s'", $operation, $path));
        }
    }

    /**
     * Ensure the normalized path is within the root directory (virtual "/" maps to $rootDir).
     */
    private function assertContained(string $normalizedPath, string $operation): void
    {
        // All normalized paths start with "/", which maps to $rootDir. They are always contained
        // as long as normalizePath has resolved ".." properly. Since normalizePath never lets
        // ".." escape past "/", this is inherently safe. But let's be defensive:
        if (! str_starts_with($normalizedPath, '/')) {
            throw new RuntimeException(sprintf("EACCES: path traversal denied, %s '%s'", $operation, $normalizedPath));
        }
    }

    private function isContained(string $normalizedPath): bool
    {
        return str_starts_with($normalizedPath, '/');
    }

    /**
     * Convert a virtual normalized path to the real filesystem path.
     */
    private function toRealPath(string $normalizedPath): string
    {
        if ($normalizedPath === '/') {
            return $this->rootDir;
        }

        return $this->rootDir.$normalizedPath;
    }

    private function guardSymlink(string $realPath, string $operation, string $userPath): void
    {
        if ($this->denySymlinks && is_link($realPath)) {
            throw new RuntimeException(sprintf("EPERM: symlinks are denied, %s '%s'", $operation, $userPath));
        }
    }

    private function isDeleted(string $normalizedPath): bool
    {
        if (isset($this->deletedPaths[$normalizedPath])) {
            return true;
        }

        // Check if any ancestor has been deleted
        $parent = $this->dirname($normalizedPath);

        while ($parent !== $normalizedPath) {
            if (isset($this->deletedPaths[$parent])) {
                return true;
            }

            $normalizedPath = $parent;
            $parent = $this->dirname($parent);
        }

        return false;
    }

    private function markDeleted(string $normalizedPath): void
    {
        $this->deletedPaths[$normalizedPath] = true;
    }

    private function undelete(string $normalizedPath): void
    {
        unset($this->deletedPaths[$normalizedPath]);
    }

    private function statRealPath(string $realPath): FsStat
    {
        $phpStat = stat($realPath);

        if ($phpStat === false) {
            throw new RuntimeException('Failed to stat real path');
        }

        return new FsStat(
            isFile: is_file($realPath),
            isDirectory: is_dir($realPath),
            isSymbolicLink: false,
            mode: UnixFileMode::permissions($phpStat['mode']),
            size: $phpStat['size'],
            mtime: $phpStat['mtime'],
        );
    }

    /**
     * Pull a file/directory from the real FS into the COW layer so it can be mutated.
     */
    private function pullIntoCow(string $normalizedPath, string $operation): void
    {
        if ($this->inMemoryFs->exists($normalizedPath)) {
            return;
        }

        if ($this->isDeleted($normalizedPath)) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, %s '%s'", $operation, $normalizedPath));
        }

        $realPath = $this->toRealPath($normalizedPath);
        $this->guardSymlink($realPath, $operation, $normalizedPath);

        if (is_file($realPath)) {
            $content = file_get_contents($realPath);

            if ($content === false) {
                throw new RuntimeException(sprintf("ENOENT: no such file or directory, %s '%s'", $operation, $normalizedPath));
            }

            $this->ensureCowParentDirs($normalizedPath);
            $this->inMemoryFs->writeFile($normalizedPath, $content);
        } elseif (is_dir($realPath)) {
            $this->ensureCowParentDirs($normalizedPath);
            $this->inMemoryFs->mkdir($normalizedPath, ['recursive' => true]);
        } else {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, %s '%s'", $operation, $normalizedPath));
        }
    }

    /**
     * Ensure parent directories exist in the COW layer, creating them from the
     * real FS or as new directories as needed.
     */
    private function ensureCowParentDirs(string $normalizedPath): void
    {
        $dir = $this->dirname($normalizedPath);

        if ($dir === '/') {
            if (! $this->inMemoryFs->exists('/')) {
                $this->inMemoryFs->mkdir('/', ['recursive' => true]);
            }

            return;
        }

        if ($this->inMemoryFs->exists($dir)) {
            return;
        }

        $this->ensureCowParentDirs($dir);
        $this->inMemoryFs->mkdir($dir, ['recursive' => true]);
    }

    /**
     * Recursively collect paths from the real filesystem.
     *
     * @param  list<string>  $paths
     */
    private function collectRealPaths(string $realDir, string $virtualDir, array &$paths): void
    {
        if ($this->isDeleted($virtualDir)) {
            return;
        }

        $paths[] = $virtualDir;

        if (! is_dir($realDir)) {
            return;
        }

        $handle = opendir($realDir);

        if ($handle === false) {
            return;
        }

        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            $childReal = $realDir.DIRECTORY_SEPARATOR.$entry;
            $childVirtual = $virtualDir === '/' ? '/'.$entry : sprintf('%s/%s', $virtualDir, $entry);

            if ($this->isDeleted($childVirtual)) {
                continue;
            }

            if ($this->denySymlinks && is_link($childReal)) {
                continue;
            }

            $paths[] = $childVirtual;

            if (is_dir($childReal) && ! is_link($childReal)) {
                $this->collectRealPaths($childReal, $childVirtual, $paths);
            }
        }

        closedir($handle);
    }
}
