<?php

declare(strict_types=1);

namespace BashBox\Filesystem;

use RuntimeException;

final readonly class ReadWriteFs implements FileSystemInterface
{
    private string $rootDir;

    public function __construct(string $rootDir)
    {
        $realRoot = realpath($rootDir);

        if ($realRoot === false || ! is_dir($rootDir)) {
            throw new RuntimeException(sprintf("ReadWriteFs root directory does not exist: '%s'", $rootDir));
        }

        $this->rootDir = $realRoot;
    }

    public function readFile(string $path): string
    {
        $this->validatePath($path, 'open');
        $realPath = $this->toRealPath($path);
        $this->assertContained($realPath, 'open', $path);

        if (! is_file($realPath)) {
            if (is_dir($realPath)) {
                throw new RuntimeException(sprintf("EISDIR: illegal operation on a directory, read '%s'", $path));
            }

            throw new RuntimeException(sprintf("ENOENT: no such file or directory, open '%s'", $path));
        }

        $content = @file_get_contents($realPath);

        if ($content === false) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, open '%s'", $path));
        }

        return $content;
    }

    public function writeFile(string $path, string $content): void
    {
        $this->validatePath($path, 'write');
        $realPath = $this->toRealPath($path);
        $this->assertContained($realPath, 'write', $path);

        $dir = dirname($realPath);
        $this->ensureDirectory($dir, $path);

        $result = @file_put_contents($realPath, $content);

        if ($result === false) {
            throw new RuntimeException(sprintf("EACCES: permission denied, write '%s'", $path));
        }
    }

    public function appendFile(string $path, string $content): void
    {
        $this->validatePath($path, 'append');
        $realPath = $this->toRealPath($path);
        $this->assertContained($realPath, 'append', $path);

        if (is_dir($realPath)) {
            throw new RuntimeException(sprintf("EISDIR: illegal operation on a directory, write '%s'", $path));
        }

        $dir = dirname($realPath);
        $this->ensureDirectory($dir, $path);

        $result = @file_put_contents($realPath, $content, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            throw new RuntimeException(sprintf("EACCES: permission denied, append '%s'", $path));
        }
    }

    public function exists(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }

        try {
            $realPath = $this->toRealPath($path);

            if (! $this->isContained($realPath)) {
                return false;
            }

            return file_exists($realPath);
        } catch (RuntimeException) {
            return false;
        }
    }

    public function stat(string $path): FsStat
    {
        $this->validatePath($path, 'stat');
        $realPath = $this->toRealPath($path);
        $this->assertContained($realPath, 'stat', $path);

        $status = @stat($realPath);

        if ($status === false) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, stat '%s'", $path));
        }

        $mode = $status['mode'];
        $type = UnixFileMode::type($mode);

        return new FsStat(
            isFile: $type === UnixFileType::RegularFile,
            isDirectory: $type === UnixFileType::Directory,
            isSymbolicLink: false,
            mode: UnixFileMode::permissions($mode),
            size: $status['size'],
            mtime: $status['mtime'],
        );
    }

    public function lstat(string $path): FsStat
    {
        $this->validatePath($path, 'lstat');
        $realPath = $this->toRealPath($path);
        $this->assertContained($realPath, 'lstat', $path);

        $status = @lstat($realPath);

        if ($status === false) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, lstat '%s'", $path));
        }

        $mode = $status['mode'];
        $type = UnixFileMode::type($mode);

        if ($type === UnixFileType::SymbolicLink) {
            return new FsStat(
                isFile: false,
                isDirectory: false,
                isSymbolicLink: true,
                mode: UnixFileMode::FULL_PERMISSIONS,
                size: $status['size'],
                mtime: $status['mtime'],
            );
        }

        return new FsStat(
            isFile: $type === UnixFileType::RegularFile,
            isDirectory: $type === UnixFileType::Directory,
            isSymbolicLink: false,
            mode: UnixFileMode::permissions($mode),
            size: $status['size'],
            mtime: $status['mtime'],
        );
    }

    public function mkdir(string $path, array $options = []): void
    {
        $this->validatePath($path, 'mkdir');
        $realPath = $this->toRealPath($path);
        $this->assertContained($realPath, 'mkdir', $path);
        $recursive = $options['recursive'] ?? false;

        if (file_exists($realPath)) {
            $status = @stat($realPath);

            if ($status !== false && UnixFileMode::isRegularFile($status['mode'])) {
                throw new RuntimeException(sprintf("EEXIST: file already exists, mkdir '%s'", $path));
            }

            if (! $recursive) {
                throw new RuntimeException(sprintf("EEXIST: directory already exists, mkdir '%s'", $path));
            }

            return;
        }

        $result = @mkdir($realPath, 0755, $recursive);

        if ($result === false) {
            if (! $recursive) {
                throw new RuntimeException(sprintf("ENOENT: no such file or directory, mkdir '%s'", $path));
            }

            throw new RuntimeException(sprintf("EACCES: permission denied, mkdir '%s'", $path));
        }
    }

    public function readdir(string $path): array
    {
        $entries = $this->readdirWithFileTypes($path);

        return array_map(fn (DirentEntry $direntEntry): string => $direntEntry->name, $entries);
    }

    public function readdirWithFileTypes(string $path): array
    {
        $this->validatePath($path, 'scandir');
        $realPath = $this->toRealPath($path);
        $this->assertContained($realPath, 'scandir', $path);

        if (! is_dir($realPath)) {
            if (! file_exists($realPath)) {
                throw new RuntimeException(sprintf("ENOENT: no such file or directory, scandir '%s'", $path));
            }

            throw new RuntimeException(sprintf("ENOTDIR: not a directory, scandir '%s'", $path));
        }

        $names = @scandir($realPath);

        if ($names === false) {
            throw new RuntimeException(sprintf("EACCES: permission denied, scandir '%s'", $path));
        }

        $entries = [];

        foreach ($names as $name) {
            if ($name === '.') {
                continue;
            }

            if ($name === '..') {
                continue;
            }

            $childPath = $realPath.DIRECTORY_SEPARATOR.$name;
            $childStatus = @lstat($childPath);

            $childType = $childStatus !== false ? UnixFileMode::type($childStatus['mode']) : null;
            $isLink = $childType === UnixFileType::SymbolicLink;
            $isDir = $childType === UnixFileType::Directory;
            $isFile = $childType === UnixFileType::RegularFile;

            $entries[] = new DirentEntry(
                name: $name,
                isFile: $isFile,
                isDirectory: $isDir,
                isSymbolicLink: $isLink,
            );
        }

        usort($entries, fn (DirentEntry $a, DirentEntry $b): int => strcmp($a->name, $b->name));

        return $entries;
    }

    public function rm(string $path, array $options = []): void
    {
        $this->validatePath($path, 'rm');
        $realPath = $this->toRealPath($path);
        $this->assertContained($realPath, 'rm', $path);
        $force = $options['force'] ?? false;
        $recursive = $options['recursive'] ?? false;

        if (! file_exists($realPath)) {
            if ($force) {
                return;
            }

            throw new RuntimeException(sprintf("ENOENT: no such file or directory, rm '%s'", $path));
        }

        $status = @lstat($realPath);

        if ($status === false) {
            if ($force) {
                return;
            }

            throw new RuntimeException(sprintf("ENOENT: no such file or directory, rm '%s'", $path));
        }

        $rmType = UnixFileMode::type($status['mode']);
        $isLink = $rmType === UnixFileType::SymbolicLink;
        $isDir = $rmType === UnixFileType::Directory;

        if ($isDir && ! $isLink) {
            $children = $this->readdir($path);

            if ($children !== []) {
                if (! $recursive) {
                    throw new RuntimeException(sprintf("ENOTEMPTY: directory not empty, rm '%s'", $path));
                }

                $normalized = $this->normalizePath($path);

                foreach ($children as $child) {
                    $childPath = $normalized === '/' ? '/'.$child : sprintf('%s/%s', $normalized, $child);
                    $this->rm($childPath, $options);
                }
            }

            $result = @rmdir($realPath);

            if ($result === false) {
                throw new RuntimeException(sprintf("EACCES: permission denied, rm '%s'", $path));
            }
        } else {
            $result = @unlink($realPath);

            if ($result === false) {
                throw new RuntimeException(sprintf("EACCES: permission denied, rm '%s'", $path));
            }
        }
    }

    public function cp(string $src, string $dest, array $options = []): void
    {
        $this->validatePath($src, 'cp');
        $this->validatePath($dest, 'cp');
        $srcReal = $this->toRealPath($src);
        $destReal = $this->toRealPath($dest);
        $this->assertContained($srcReal, 'cp', $src);
        $this->assertContained($destReal, 'cp', $dest);
        $recursive = $options['recursive'] ?? false;

        if (! file_exists($srcReal)) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, cp '%s'", $src));
        }

        $status = @stat($srcReal);

        if ($status === false) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, cp '%s'", $src));
        }

        $cpType = UnixFileMode::type($status['mode']);
        $isFile = $cpType === UnixFileType::RegularFile;
        $isDir = $cpType === UnixFileType::Directory;

        if ($isFile) {
            $destDir = dirname($destReal);
            $this->ensureDirectory($destDir, $dest);

            $content = @file_get_contents($srcReal);

            if ($content === false) {
                throw new RuntimeException(sprintf("ENOENT: no such file or directory, cp '%s'", $src));
            }

            $result = @file_put_contents($destReal, $content);

            if ($result === false) {
                throw new RuntimeException(sprintf("EACCES: permission denied, cp '%s'", $dest));
            }
        } elseif ($isDir) {
            if (! $recursive) {
                throw new RuntimeException(sprintf("EISDIR: is a directory, cp '%s'", $src));
            }

            $this->mkdir($dest, ['recursive' => true]);

            $srcNorm = $this->normalizePath($src);
            $destNorm = $this->normalizePath($dest);
            $children = $this->readdir($src);

            foreach ($children as $child) {
                $srcChild = $srcNorm === '/' ? '/'.$child : sprintf('%s/%s', $srcNorm, $child);
                $destChild = $destNorm === '/' ? '/'.$child : sprintf('%s/%s', $destNorm, $child);
                $this->cp($srcChild, $destChild, $options);
            }
        }
    }

    public function mv(string $src, string $dest): void
    {
        $this->validatePath($src, 'rename');
        $this->validatePath($dest, 'rename');
        $srcReal = $this->toRealPath($src);
        $destReal = $this->toRealPath($dest);
        $this->assertContained($srcReal, 'rename', $src);
        $this->assertContained($destReal, 'rename', $dest);

        if (! file_exists($srcReal)) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, rename '%s'", $src));
        }

        $destDir = dirname($destReal);
        $this->ensureDirectory($destDir, $dest);

        $result = @rename($srcReal, $destReal);

        if ($result === false) {
            throw new RuntimeException(sprintf("EACCES: permission denied, rename '%s'", $src));
        }
    }

    public function resolvePath(string $base, string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $this->normalizePath($path);
        }

        $combined = $base === '/' ? '/'.$path : sprintf('%s/%s', $base, $path);

        return $this->normalizePath($combined);
    }

    /**
     * @return list<string>
     */
    public function getAllPaths(): array
    {
        $paths = [];
        $this->collectPaths($this->rootDir, '/', $paths);
        sort($paths);

        return $paths;
    }

    public function chmod(string $path, int $mode): void
    {
        $this->validatePath($path, 'chmod');
        $realPath = $this->toRealPath($path);
        $this->assertContained($realPath, 'chmod', $path);

        if (! file_exists($realPath)) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, chmod '%s'", $path));
        }

        $result = @chmod($realPath, $mode);

        if ($result === false) {
            throw new RuntimeException(sprintf("EACCES: permission denied, chmod '%s'", $path));
        }
    }

    public function symlink(string $target, string $linkPath): void
    {
        $this->validatePath($linkPath, 'symlink');
        $realLinkPath = $this->toRealPath($linkPath);
        $this->assertContained($realLinkPath, 'symlink', $linkPath);

        if (file_exists($realLinkPath)) {
            throw new RuntimeException(sprintf("EEXIST: file already exists, symlink '%s'", $linkPath));
        }

        $result = @symlink($this->normalizeSymlinkTarget($target), $realLinkPath);

        if ($result === false) {
            throw new RuntimeException(sprintf("EACCES: permission denied, symlink '%s'", $linkPath));
        }
    }

    public function link(string $existingPath, string $newPath): void
    {
        $this->validatePath($existingPath, 'link');
        $this->validatePath($newPath, 'link');
        $existingReal = $this->toRealPath($existingPath);
        $newReal = $this->toRealPath($newPath);
        $this->assertContained($existingReal, 'link', $existingPath);
        $this->assertContained($newReal, 'link', $newPath);

        if (! is_file($existingReal)) {
            if (! file_exists($existingReal)) {
                throw new RuntimeException(sprintf("ENOENT: no such file or directory, link '%s'", $existingPath));
            }

            throw new RuntimeException(sprintf("EPERM: operation not permitted, link '%s'", $existingPath));
        }

        if (file_exists($newReal)) {
            throw new RuntimeException(sprintf("EEXIST: file already exists, link '%s'", $newPath));
        }

        $result = @link($existingReal, $newReal);

        if ($result === false) {
            throw new RuntimeException(sprintf("EACCES: permission denied, link '%s'", $existingPath));
        }
    }

    public function readlink(string $path): string
    {
        $this->validatePath($path, 'readlink');
        $realPath = $this->toRealPath($path);
        $this->assertContained($realPath, 'readlink', $path);

        if (! is_link($realPath)) {
            if (! file_exists($realPath)) {
                throw new RuntimeException(sprintf("ENOENT: no such file or directory, readlink '%s'", $path));
            }

            throw new RuntimeException(sprintf("EINVAL: invalid argument, readlink '%s'", $path));
        }

        $target = readlink($realPath);

        if ($target === false) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, readlink '%s'", $path));
        }

        return $this->virtualizeSymlinkTarget($target);
    }

    public function realpath(string $path): string
    {
        $this->validatePath($path, 'realpath');
        $realPath = $this->toRealPath($path);
        $this->assertContained($realPath, 'realpath', $path);

        $resolved = realpath($realPath);

        if ($resolved === false) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, realpath '%s'", $path));
        }

        if (! str_starts_with($resolved, $this->rootDir)) {
            throw new RuntimeException(sprintf("EACCES: path traversal denied, realpath '%s'", $path));
        }

        if ($resolved === $this->rootDir) {
            return '/';
        }

        return substr($resolved, strlen($this->rootDir));
    }

    public function utimes(string $path, int $mtime): void
    {
        $this->validatePath($path, 'utimes');
        $realPath = $this->toRealPath($path);
        $this->assertContained($realPath, 'utimes', $path);

        if (! file_exists($realPath)) {
            throw new RuntimeException(sprintf("ENOENT: no such file or directory, utimes '%s'", $path));
        }

        $result = @touch($realPath, $mtime, $mtime);

        if ($result === false) {
            throw new RuntimeException(sprintf("EACCES: permission denied, utimes '%s'", $path));
        }
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

    private function toRealPath(string $virtualPath): string
    {
        $normalized = $this->normalizePath($virtualPath);

        if ($normalized === '/') {
            return $this->rootDir;
        }

        return $this->rootDir.$normalized;
    }

    private function assertContained(string $realPath, string $operation, string $userPath): void
    {
        if (! $this->isContained($realPath)) {
            throw new RuntimeException(sprintf("EACCES: path traversal denied, %s '%s'", $operation, $userPath));
        }
    }

    private function isContained(string $realPath): bool
    {
        return $realPath === $this->rootDir || str_starts_with($realPath, $this->rootDir.'/');
    }

    private function validatePath(string $path, string $operation): void
    {
        if (str_contains($path, "\0")) {
            throw new RuntimeException(sprintf("ENOENT: path contains null byte, %s '%s'", $operation, $path));
        }
    }

    private function ensureDirectory(string $realDir, string $userPath): void
    {
        if (! is_dir($realDir)) {
            $result = @mkdir($realDir, 0755, true);

            if ($result === false) {
                throw new RuntimeException(sprintf("ENOENT: no such file or directory, write '%s'", $userPath));
            }
        }
    }

    private function normalizeSymlinkTarget(string $target): string
    {
        if (! str_starts_with($target, '/')) {
            return $target;
        }

        $this->validatePath($target, 'symlink');
        $realTarget = $this->toRealPath($target);
        $this->assertContained($realTarget, 'symlink', $target);

        return $realTarget;
    }

    private function virtualizeSymlinkTarget(string $target): string
    {
        if ($target === $this->rootDir) {
            return '/';
        }

        if (str_starts_with($target, $this->rootDir.'/')) {
            return substr($target, strlen($this->rootDir));
        }

        return $target;
    }

    /**
     * @param  list<string>  $paths
     */
    private function collectPaths(string $realDir, string $virtualDir, array &$paths): void
    {
        $paths[] = $virtualDir;

        if (! is_dir($realDir)) {
            return;
        }

        $names = @scandir($realDir);

        if ($names === false) {
            return;
        }

        foreach ($names as $name) {
            if ($name === '.') {
                continue;
            }

            if ($name === '..') {
                continue;
            }

            $childReal = $realDir.DIRECTORY_SEPARATOR.$name;
            $childVirtual = $virtualDir === '/' ? '/'.$name : sprintf('%s/%s', $virtualDir, $name);
            $paths[] = $childVirtual;

            $childStatus = @lstat($childReal);

            if ($childStatus !== false) {
                $childType = UnixFileMode::type($childStatus['mode']);
                $isDir = $childType === UnixFileType::Directory;
                $isLink = $childType === UnixFileType::SymbolicLink;

                if ($isDir && ! $isLink) {
                    $this->collectPaths($childReal, $childVirtual, $paths);
                }
            }
        }
    }
}
