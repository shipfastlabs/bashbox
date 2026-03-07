<?php

declare(strict_types=1);

namespace BashBox\Filesystem;

use RuntimeException;

final class InMemoryFs implements FileSystemInterface
{
    /**
     * @var array<string, array{type: string, content?: string, target?: string, mode: int, mtime: int}>
     */
    private array $data = [];

    /**
     * @param  array<string, string>  $initialFiles
     */
    public function __construct(array $initialFiles = [])
    {
        $this->data['/'] = ['type' => 'directory', 'mode' => 0755, 'mtime' => time()];

        foreach ($initialFiles as $path => $content) {
            $this->writeFile($path, $content);
        }
    }

    public function readFile(string $path): string
    {
        $this->validatePath($path, 'open');
        $resolved = $this->resolvePathWithSymlinks($path);
        $entry = $this->data[$resolved] ?? null;

        if ($entry === null) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, open '%s'", $path));
        }

        if ($entry['type'] !== 'file') {
            throw new RuntimeException(sprintf("EISDIR: illegal operation on a directory, read '%s'", $path));
        }

        return $entry['content'] ?? '';
    }

    public function writeFile(string $path, string $content): void
    {
        $this->validatePath($path, 'write');
        $normalized = $this->normalizePath($path);
        $this->ensureParentDirs($normalized);

        $this->data[$normalized] = [
            'type' => 'file',
            'content' => $content,
            'mode' => 0644,
            'mtime' => time(),
        ];
    }

    public function appendFile(string $path, string $content): void
    {
        $this->validatePath($path, 'append');
        $normalized = $this->normalizePath($path);
        $existing = $this->data[$normalized] ?? null;

        if ($existing !== null && $existing['type'] === 'directory') {
            throw new RuntimeException(sprintf("EISDIR: illegal operation on a directory, write '%s'", $path));
        }

        if ($existing !== null && $existing['type'] === 'file') {
            $this->data[$normalized]['content'] = ($existing['content'] ?? '').$content;
            $this->data[$normalized]['mtime'] = time();
        } else {
            $this->writeFile($path, $content);
        }
    }

    public function exists(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }

        try {
            $resolved = $this->resolvePathWithSymlinks($path);

            return isset($this->data[$resolved]);
        } catch (RuntimeException) {
            return false;
        }
    }

    public function stat(string $path): FsStat
    {
        $this->validatePath($path, 'stat');
        $resolved = $this->resolvePathWithSymlinks($path);
        $entry = $this->data[$resolved] ?? null;

        if ($entry === null) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, stat '%s'", $path));
        }

        $size = 0;

        if ($entry['type'] === 'file' && isset($entry['content'])) {
            $size = strlen($entry['content']);
        }

        return new FsStat(
            isFile: $entry['type'] === 'file',
            isDirectory: $entry['type'] === 'directory',
            isSymbolicLink: false,
            mode: $entry['mode'],
            size: $size,
            mtime: $entry['mtime'],
        );
    }

    public function lstat(string $path): FsStat
    {
        $this->validatePath($path, 'lstat');
        $resolved = $this->resolveIntermediateSymlinks($path);
        $entry = $this->data[$resolved] ?? null;

        if ($entry === null) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, lstat '%s'", $path));
        }

        if ($entry['type'] === 'symlink') {
            return new FsStat(
                isFile: false,
                isDirectory: false,
                isSymbolicLink: true,
                mode: $entry['mode'],
                size: strlen($entry['target'] ?? ''),
                mtime: $entry['mtime'],
            );
        }

        $size = 0;

        if ($entry['type'] === 'file' && isset($entry['content'])) {
            $size = strlen($entry['content']);
        }

        return new FsStat(
            isFile: $entry['type'] === 'file',
            isDirectory: $entry['type'] === 'directory',
            isSymbolicLink: false,
            mode: $entry['mode'],
            size: $size,
            mtime: $entry['mtime'],
        );
    }

    public function mkdir(string $path, array $options = []): void
    {
        $this->validatePath($path, 'mkdir');
        $normalized = $this->normalizePath($path);
        $recursive = $options['recursive'] ?? false;

        if (isset($this->data[$normalized])) {
            $entry = $this->data[$normalized];

            if ($entry['type'] === 'file') {
                throw new RuntimeException(sprintf("EEXIST: file already exists, mkdir '%s'", $path));
            }

            if (! $recursive) {
                throw new RuntimeException(sprintf("EEXIST: directory already exists, mkdir '%s'", $path));
            }

            return;
        }

        $parent = $this->dirname($normalized);

        if ($parent !== '/' && ! isset($this->data[$parent])) {
            if ($recursive) {
                $this->mkdir($parent, ['recursive' => true]);
            } else {
                throw new RuntimeException(sprintf("ENOENT: no such file or directory, mkdir '%s'", $path));
            }
        }

        $this->data[$normalized] = ['type' => 'directory', 'mode' => 0755, 'mtime' => time()];
    }

    public function readdir(string $path): array
    {
        $entries = $this->readdirWithFileTypes($path);

        return array_map(fn (DirentEntry $e): string => $e->name, $entries);
    }

    public function readdirWithFileTypes(string $path): array
    {
        $this->validatePath($path, 'scandir');
        $normalized = $this->normalizePath($path);
        $entry = $this->data[$normalized] ?? null;

        if ($entry === null) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, scandir '%s'", $path));
        }

        $seen = [];

        while ($entry !== null && $entry['type'] === 'symlink') {
            if (isset($seen[$normalized])) {
                throw new RuntimeException(sprintf("ELOOP: too many levels of symbolic links, scandir '%s'", $path));
            }

            $seen[$normalized] = true;
            $normalized = $this->resolveSymlink($normalized, $entry['target'] ?? '');
            $entry = $this->data[$normalized] ?? null;
        }

        if ($entry === null) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, scandir '%s'", $path));
        }

        if ($entry['type'] !== 'directory') {
            throw new RuntimeException(sprintf("ENOTDIR: not a directory, scandir '%s'", $path));
        }

        $prefix = $normalized === '/' ? '/' : $normalized.'/';
        $entriesMap = [];

        foreach ($this->data as $p => $fsEntry) {
            if ($p === $normalized) {
                continue;
            }

            if (str_starts_with($p, $prefix)) {
                $rest = substr($p, strlen($prefix));
                $slashPos = strpos($rest, '/');
                $name = $slashPos !== false ? substr($rest, 0, $slashPos) : $rest;

                if ($name !== '' && ! isset($entriesMap[$name])) {
                    $entriesMap[$name] = new DirentEntry(
                        name: $name,
                        isFile: $fsEntry['type'] === 'file',
                        isDirectory: $fsEntry['type'] === 'directory',
                        isSymbolicLink: $fsEntry['type'] === 'symlink',
                    );
                }
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
        $entry = $this->data[$normalized] ?? null;
        $recursive = $options['recursive'] ?? false;
        $force = $options['force'] ?? false;

        if ($entry === null) {
            if ($force) {
                return;
            }

            throw new RuntimeException(sprintf("ENOENT: no such file or directory, rm '%s'", $path));
        }

        if ($entry['type'] === 'directory') {
            $children = $this->readdir($normalized);

            if ($children !== []) {
                if (! $recursive) {
                    throw new RuntimeException(sprintf("ENOTEMPTY: directory not empty, rm '%s'", $path));
                }

                foreach ($children as $child) {
                    $childPath = $normalized === '/' ? '/'.$child : sprintf('%s/%s', $normalized, $child);
                    $this->rm($childPath, $options);
                }
            }
        }

        unset($this->data[$normalized]);
    }

    public function cp(string $src, string $dest, array $options = []): void
    {
        $this->validatePath($src, 'cp');
        $this->validatePath($dest, 'cp');
        $srcNorm = $this->normalizePath($src);
        $destNorm = $this->normalizePath($dest);
        $srcEntry = $this->data[$srcNorm] ?? null;
        $recursive = $options['recursive'] ?? false;

        if ($srcEntry === null) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, cp '%s'", $src));
        }

        if ($srcEntry['type'] === 'file') {
            $this->ensureParentDirs($destNorm);
            $this->data[$destNorm] = $srcEntry;
        } elseif ($srcEntry['type'] === 'symlink') {
            $this->ensureParentDirs($destNorm);
            $this->data[$destNorm] = $srcEntry;
        } elseif ($srcEntry['type'] === 'directory') {
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
        return array_keys($this->data);
    }

    public function chmod(string $path, int $mode): void
    {
        $this->validatePath($path, 'chmod');
        $normalized = $this->normalizePath($path);

        if (! isset($this->data[$normalized])) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, chmod '%s'", $path));
        }

        $this->data[$normalized]['mode'] = $mode;
    }

    public function symlink(string $target, string $linkPath): void
    {
        $this->validatePath($linkPath, 'symlink');
        $normalized = $this->normalizePath($linkPath);

        if (isset($this->data[$normalized])) {
            throw new RuntimeException(sprintf("EEXIST: file already exists, symlink '%s'", $linkPath));
        }

        $this->ensureParentDirs($normalized);
        $this->data[$normalized] = [
            'type' => 'symlink',
            'target' => $target,
            'mode' => 0777,
            'mtime' => time(),
        ];
    }

    public function link(string $existingPath, string $newPath): void
    {
        $this->validatePath($existingPath, 'link');
        $this->validatePath($newPath, 'link');
        $existingNorm = $this->normalizePath($existingPath);
        $newNorm = $this->normalizePath($newPath);

        $entry = $this->data[$existingNorm] ?? null;

        if ($entry === null) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, link '%s'", $existingPath));
        }

        if ($entry['type'] !== 'file') {
            throw new RuntimeException(sprintf("EPERM: operation not permitted, link '%s'", $existingPath));
        }

        if (isset($this->data[$newNorm])) {
            throw new RuntimeException(sprintf("EEXIST: file already exists, link '%s'", $newPath));
        }

        $this->ensureParentDirs($newNorm);
        $this->data[$newNorm] = $entry;
    }

    public function readlink(string $path): string
    {
        $this->validatePath($path, 'readlink');
        $normalized = $this->normalizePath($path);
        $entry = $this->data[$normalized] ?? null;

        if ($entry === null) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, readlink '%s'", $path));
        }

        if ($entry['type'] !== 'symlink') {
            throw new RuntimeException(sprintf("EINVAL: invalid argument, readlink '%s'", $path));
        }

        return $entry['target'] ?? '';
    }

    public function realpath(string $path): string
    {
        $this->validatePath($path, 'realpath');
        $resolved = $this->resolvePathWithSymlinks($path);

        if (! isset($this->data[$resolved])) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, realpath '%s'", $path));
        }

        return $resolved;
    }

    public function utimes(string $path, int $mtime): void
    {
        $this->validatePath($path, 'utimes');
        $normalized = $this->normalizePath($path);
        $resolved = $this->resolvePathWithSymlinks($normalized);

        if (! isset($this->data[$resolved])) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, utimes '%s'", $path));
        }

        $this->data[$resolved]['mtime'] = $mtime;
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

    private function ensureParentDirs(string $path): void
    {
        $dir = $this->dirname($path);

        if ($dir === '/') {
            return;
        }

        if (! isset($this->data[$dir])) {
            $this->ensureParentDirs($dir);
            $this->data[$dir] = ['type' => 'directory', 'mode' => 0755, 'mtime' => time()];
        }
    }

    private function validatePath(string $path, string $operation): void
    {
        if (str_contains($path, "\0")) {
            throw new RuntimeException(sprintf("ENOENT: path contains null byte, %s '%s'", $operation, $path));
        }
    }

    private function resolveSymlink(string $symlinkPath, string $target): string
    {
        if (str_starts_with($target, '/')) {
            return $this->normalizePath($target);
        }

        $dir = $this->dirname($symlinkPath);

        return $this->normalizePath($dir === '/' ? '/'.$target : sprintf('%s/%s', $dir, $target));
    }

    private function resolveIntermediateSymlinks(string $path): string
    {
        $normalized = $this->normalizePath($path);

        if ($normalized === '/') {
            return '/';
        }

        $parts = explode('/', ltrim($normalized, '/'));

        if (count($parts) <= 1) {
            return $normalized;
        }

        $resolvedPath = '';
        $seen = [];

        for ($i = 0; $i < count($parts) - 1; $i++) {
            $resolvedPath .= '/'.$parts[$i];
            $entry = $this->data[$resolvedPath] ?? null;
            $loopCount = 0;

            while ($entry !== null && $entry['type'] === 'symlink' && $loopCount < 40) {
                if (isset($seen[$resolvedPath])) {
                    throw new RuntimeException(sprintf("ELOOP: too many levels of symbolic links, lstat '%s'", $path));
                }

                $seen[$resolvedPath] = true;
                $resolvedPath = $this->resolveSymlink($resolvedPath, $entry['target'] ?? '');
                $entry = $this->data[$resolvedPath] ?? null;
                $loopCount++;
            }

            if ($loopCount >= 40) {
                throw new RuntimeException(sprintf("ELOOP: too many levels of symbolic links, lstat '%s'", $path));
            }
        }

        return $resolvedPath.'/'.$parts[count($parts) - 1];
    }

    private function resolvePathWithSymlinks(string $path): string
    {
        $normalized = $this->normalizePath($path);

        if ($normalized === '/') {
            return '/';
        }

        $parts = explode('/', ltrim($normalized, '/'));
        $resolvedPath = '';
        $seen = [];

        foreach ($parts as $part) {
            $resolvedPath .= '/'.$part;
            $entry = $this->data[$resolvedPath] ?? null;
            $loopCount = 0;

            while ($entry !== null && $entry['type'] === 'symlink' && $loopCount < 40) {
                if (isset($seen[$resolvedPath])) {
                    throw new RuntimeException(sprintf("ELOOP: too many levels of symbolic links, open '%s'", $path));
                }

                $seen[$resolvedPath] = true;
                $resolvedPath = $this->resolveSymlink($resolvedPath, $entry['target'] ?? '');
                $entry = $this->data[$resolvedPath] ?? null;
                $loopCount++;
            }

            if ($loopCount >= 40) {
                throw new RuntimeException(sprintf("ELOOP: too many levels of symbolic links, open '%s'", $path));
            }
        }

        return $resolvedPath;
    }
}
