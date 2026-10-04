<?php

declare(strict_types=1);

namespace BashBox\Filesystem;

use RuntimeException;

/**
 * Reads and writes a real directory, resolving every lookup on disk one component at a time and denying anything that leaves the root.
 *
 * Limitation: resolve-then-act isn't atomic, so a host process swapping a directory for a symlink in between could slip past (PHP lacks openat()).
 */
final readonly class ReadWriteFs implements FileSystemInterface
{
    private string $rootDir;

    /**
     * @param  bool  $allowSymlinks  false hides on-disk symlinks and refuses any lookup through one
     * @param  DiskQuota  $diskQuota  counts what the sandbox adds: bytes written less bytes removed, and entries made
     */
    public function __construct(string $rootDir, private bool $allowSymlinks = true, private DiskQuota $diskQuota = new DiskQuota)
    {
        $realRoot = realpath($rootDir);

        if ($realRoot === false || ! is_dir($realRoot)) {
            throw new RuntimeException(sprintf("Filesystem root directory does not exist: '%s'", $rootDir));
        }

        $this->rootDir = $realRoot;
    }

    public function readFile(string $path): string
    {
        $real = $this->resolve($path, 'open');

        if (is_dir($real)) {
            $this->fail('EISDIR: illegal operation on a directory', 'read', $path);
        }

        if (! is_file($real)) {
            $this->fail('ENOENT: no such file or directory', 'open', $path);
        }

        return $this->host(fn (): string|false => file_get_contents($real), 'open', $path);
    }

    public function writeFile(string $path, string $content): void
    {
        $this->put($path, $content, 0);
    }

    /** Atomic on the host and never following the new name: mkdir(), or tempnam() then link(), since fopen('x') would follow a dangling symlink. */
    public function createExclusive(string $path, bool $directory = false): void
    {
        $operation = $directory ? 'mkdir' : 'open';
        $real = $this->resolve($path, $operation, false);
        $this->diskQuota->charge(0, 1, $path);
        set_error_handler(fn (): bool => true);
        $umask = umask(0077);
        $temp = $directory ? false : tempnam(dirname($real), '.bashbox');

        try {
            $created = $directory ? mkdir($real, 0700) : $temp !== false && link($temp, $real);
        } finally {
            if ($temp !== false) {
                unlink($temp);
            }

            umask($umask);
            restore_error_handler();
        }

        if (! $created) {
            $this->diskQuota->charge(0, -1, $path);
            $this->fail(match (true) {
                file_exists($real) || is_link($real) => 'EEXIST: file already exists',
                is_dir(dirname($real)) => 'EACCES: permission denied',
                file_exists(dirname($real)) => 'ENOTDIR: not a directory',
                default => 'ENOENT: no such file or directory',
            }, $operation, $path);
        }

    }

    public function appendFile(string $path, string $content): void
    {
        $this->put($path, $content, FILE_APPEND | LOCK_EX);
    }

    public function exists(string $path): bool
    {
        try {
            return file_exists($this->resolve($path, 'access'));
        } catch (RuntimeException) {
            return false;
        }
    }

    public function stat(string $path): FsStat
    {
        return $this->statPath($path, 'stat', true);
    }

    public function lstat(string $path): FsStat
    {
        return $this->statPath($path, 'lstat', false);
    }

    public function mkdir(string $path, array $options = []): void
    {
        $real = $this->resolve($path, 'mkdir', false);
        $recursive = $options['recursive'] ?? false;

        if (file_exists($real)) {
            if ($recursive && is_dir($real)) {
                return;
            }

            $this->fail('EEXIST: file already exists', 'mkdir', $path);
        }

        if (! $recursive && ! is_dir(dirname($real))) {
            $this->fail('ENOENT: no such file or directory', 'mkdir', $path);
        }

        $this->diskQuota->charge(0, $this->missing($real), $path);
        $this->host(fn (): bool => mkdir($real, 0755, $recursive), 'mkdir', $path);
    }

    public function readdir(string $path): array
    {
        return array_map(fn (DirentEntry $direntEntry): string => $direntEntry->name, $this->readdirWithFileTypes($path));
    }

    public function readdirWithFileTypes(string $path): array
    {
        $real = $this->resolve($path, 'scandir');

        if (! is_dir($real)) {
            $this->fail(file_exists($real) ? 'ENOTDIR: not a directory' : 'ENOENT: no such file or directory', 'scandir', $path);
        }

        $names = $this->host(fn (): array|false => scandir($real), 'scandir', $path);
        $entries = [];

        foreach (array_diff($names, ['.', '..']) as $name) {
            $type = @filetype($real.'/'.$name);

            if ($type !== 'link' || $this->allowSymlinks) {
                $entries[] = new DirentEntry($name, $type === 'file', $type === 'dir', $type === 'link');
            }
        }

        usort($entries, fn (DirentEntry $a, DirentEntry $b): int => strcmp($a->name, $b->name));

        return $entries;
    }

    public function rm(string $path, array $options = []): void
    {
        $real = $this->resolve($path, 'rm', false);

        if (! file_exists($real) && ! is_link($real)) {
            if ($options['force'] ?? false) {
                return;
            }

            $this->fail('ENOENT: no such file or directory', 'rm', $path);
        }

        if ($real === $this->rootDir) {
            $this->fail('EPERM: operation not permitted', 'rm', $path);
        }

        if (is_link($real) || ! is_dir($real)) {
            $stat = $this->lstat($path);
            $this->host(fn (): bool => unlink($real), 'rm', $path);
            // Only the last name of a file frees its data.
            $this->diskQuota->charge($stat->isFile && $stat->nlink === 1 ? -$stat->size : 0, -1, $path);

            return;
        }

        $children = $this->readdir($path);

        if ($children !== [] && ! ($options['recursive'] ?? false)) {
            $this->fail('ENOTEMPTY: directory not empty', 'rm', $path);
        }

        foreach ($children as $child) {
            $this->rm($path.'/'.$child, $options);
        }

        $this->host(fn (): bool => rmdir($real), 'rm', $path);
        $this->diskQuota->charge(0, -1, $path);
    }

    public function cp(string $src, string $dest, array $options = []): void
    {
        $srcReal = $this->resolve($src, 'cp');

        if (! is_dir($srcReal)) {
            $this->writeFile($dest, $this->readFile($src));

            if ($options['preserve'] ?? false) {
                $stat = $this->stat($src);
                $this->chmod($dest, $stat->mode);
                $this->utimes($dest, $stat->mtime);
            }

            return;
        }

        if (! ($options['recursive'] ?? false)) {
            $this->fail('EISDIR: is a directory', 'cp', $src);
        }

        if (str_starts_with($this->resolve($dest, 'cp').'/', $srcReal.'/')) {
            $this->fail('EINVAL: cannot copy a directory into itself', 'cp', $src);
        }

        $this->mkdir($dest, ['recursive' => true]);

        foreach ($this->readdir($src) as $child) {
            $this->cp($src.'/'.$child, $dest.'/'.$child, $options);
        }
    }

    public function mv(string $src, string $dest): void
    {
        $srcReal = $this->resolve($src, 'rename', false);
        $destReal = $this->resolve($dest, 'rename', false);

        if (! file_exists($srcReal) && ! is_link($srcReal)) {
            $this->fail('ENOENT: no such file or directory', 'rename', $src);
        }

        $this->ensureParent($destReal, 'rename', $dest);
        $this->host(fn (): bool => rename($srcReal, $destReal), 'rename', $src);
    }

    public function resolvePath(string $base, string $path): string
    {
        return VirtualPath::resolve($base, $path);
    }

    public function getAllPaths(): array
    {
        $paths = ['/'];
        $this->collectPaths($this->rootDir, '', $paths);
        sort($paths);

        return $paths;
    }

    public function chmod(string $path, int $mode): void
    {
        $real = $this->resolve($path, 'chmod');

        if (! file_exists($real)) {
            $this->fail('ENOENT: no such file or directory', 'chmod', $path);
        }

        $this->host(fn (): bool => chmod($real, $mode), 'chmod', $path);
    }

    public function symlink(string $target, string $linkPath): void
    {
        $real = $this->resolve($linkPath, 'symlink', false);

        if (! $this->allowSymlinks) {
            $this->fail('EPERM: symlinks are denied', 'symlink', $linkPath);
        }

        if (file_exists($real) || is_link($real)) {
            $this->fail('EEXIST: file already exists', 'symlink', $linkPath);
        }

        // Absolute targets are virtual paths; store them as host paths so the link also works on disk.
        if (str_starts_with($target, '/')) {
            $target = $this->rootDir.$target;
        }

        $this->diskQuota->charge(0, 1, $linkPath);
        $this->host(fn (): bool => symlink($target, $real), 'symlink', $linkPath);
    }

    public function link(string $existingPath, string $newPath): void
    {
        $existingReal = $this->resolve($existingPath, 'link');
        $newReal = $this->resolve($newPath, 'link', false);

        if (is_dir($existingReal)) {
            $this->fail('EPERM: operation not permitted', 'link', $existingPath);
        }

        if (! file_exists($existingReal)) {
            $this->fail('ENOENT: no such file or directory', 'link', $existingPath);
        }

        if (file_exists($newReal) || is_link($newReal)) {
            $this->fail('EEXIST: file already exists', 'link', $newPath);
        }

        $this->diskQuota->charge(0, 1, $newPath);
        $this->host(fn (): bool => link($existingReal, $newReal), 'link', $newPath);
    }

    public function readlink(string $path): string
    {
        $real = $this->resolve($path, 'readlink', false);

        if (! is_link($real)) {
            $this->fail(file_exists($real) ? 'EINVAL: invalid argument' : 'ENOENT: no such file or directory', 'readlink', $path);
        }

        return $this->toVirtual((string) readlink($real));
    }

    public function realpath(string $path): string
    {
        $real = $this->resolve($path, 'realpath');

        if (! file_exists($real)) {
            $this->fail('ENOENT: no such file or directory', 'realpath', $path);
        }

        return $this->toVirtual($real);
    }

    public function utimes(string $path, int $mtime): void
    {
        $real = $this->resolve($path, 'utimes');

        if (! file_exists($real)) {
            $this->fail('ENOENT: no such file or directory', 'utimes', $path);
        }

        $this->host(fn (): bool => touch($real, $mtime, $mtime), 'utimes', $path);
    }

    private function put(string $path, string $content, int $flags): void
    {
        $real = $this->resolve($path, 'open');

        if (is_dir($real)) {
            $this->fail('EISDIR: illegal operation on a directory', 'open', $path);
        }

        clearstatcache(true, $real);
        $replaced = ($flags & FILE_APPEND) === 0 && is_file($real) ? (int) filesize($real) : 0;
        $this->diskQuota->charge(strlen($content) - $replaced, $this->missing($real), $path);
        $this->ensureParent($real, 'open', $path);
        $this->host(fn (): int|false => file_put_contents($real, $content, $flags), 'open', $path);
    }

    /** Entries a write to this host path would create: the path itself and any missing parent directories. */
    private function missing(string $real): int
    {
        $count = 0;

        while (! file_exists($real) && ! is_link($real)) {
            $count++;
            $real = dirname($real);
        }

        return $count;
    }

    private function statPath(string $path, string $operation, bool $followLast): FsStat
    {
        // Resolved paths contain no symlinks except an unfollowed last component, so lstat() is right for both.
        $real = $this->resolve($path, $operation, $followLast);
        clearstatcache(true, $real);
        $status = file_exists($real) || is_link($real) ? lstat($real) : false;

        if ($status === false) {
            $this->fail('ENOENT: no such file or directory', $operation, $path);
        }

        $type = $status['mode'] & 0o170000;

        return new FsStat(
            isFile: $type === 0o100000,
            isDirectory: $type === 0o040000,
            isSymbolicLink: $type === 0o120000,
            mode: $type === 0o120000 ? 0o777 : $status['mode'] & 0o7777,
            size: $status['size'],
            mtime: $status['mtime'],
            ino: $status['ino'],
            nlink: $status['nlink'],
        );
    }

    private function ensureParent(string $real, string $operation, string $path): void
    {
        $dir = dirname($real);

        if (! is_dir($dir)) {
            $this->host(fn (): bool => mkdir($dir, 0755, true), $operation, $path, 'ENOENT: no such file or directory');
        }
    }

    /** Map a virtual path to a host path inside the root, following symlinks (the last only when $followLast); escapes are denied. */
    private function resolve(string $path, string $operation, bool $followLast = true): string
    {
        if (str_contains($path, "\0")) {
            $this->fail('ENOENT: path contains null byte', $operation, $path);
        }

        $pending = explode('/', VirtualPath::normalize($path));
        $real = $this->rootDir;
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
                if ($real === $this->rootDir) {
                    $this->fail('EACCES: path traversal denied', $operation, $path);
                }

                $real = dirname($real);

                continue;
            }

            $next = $real.'/'.$part;

            if (is_link($next) && ! $this->allowSymlinks) {
                $this->fail('EPERM: symlinks are denied', $operation, $path);
            }

            if ($pending !== [] && ! is_link($next) && file_exists($next) && ! is_dir($next)) {
                $this->fail('ENOTDIR: not a directory', $operation, $path);
            }

            if (! is_link($next) || (! $followLast && $pending === [])) {
                $real = $next;

                continue;
            }

            if (++$hops > VirtualPath::MAX_SYMLINKS) {
                $this->fail('ELOOP: too many levels of symbolic links', $operation, $path);
            }

            $target = (string) readlink($next);

            if (str_starts_with($target, '/')) {
                $target = $this->canonical($target);

                if (! $this->isInside($target)) {
                    $this->fail('EACCES: path traversal denied', $operation, $path);
                }

                $real = $this->rootDir;
                $target = substr($target, strlen($this->rootDir));
            }

            $pending = [...explode('/', $target), ...$pending];
        }

        return $real;
    }

    private function isInside(string $hostPath): bool
    {
        return $hostPath === $this->rootDir || str_starts_with($hostPath, $this->rootDir.'/');
    }

    /** Absolute host paths may reach the root through an alias (macOS /var -> /private/var). */
    private function canonical(string $hostPath): string
    {
        return $this->isInside($hostPath) ? $hostPath : (string) realpath($hostPath);
    }

    /** Turn a host path (or symlink target) inside the root into its virtual path; anything else is returned as is. */
    private function toVirtual(string $hostPath): string
    {
        $canonical = str_starts_with($hostPath, '/') ? $this->canonical($hostPath) : $hostPath;

        return $this->isInside($canonical) ? substr($canonical, strlen($this->rootDir)) ?: '/' : $hostPath;
    }

    /**
     * @param  list<string>  $paths
     */
    private function collectPaths(string $realDir, string $virtualDir, array &$paths): void
    {
        foreach (array_diff(@scandir($realDir) ?: [], ['.', '..']) as $name) {
            $type = @filetype($realDir.'/'.$name);

            if ($type === 'link' && ! $this->allowSymlinks) {
                continue;
            }

            $paths[] = $virtualDir.'/'.$name;

            if ($type === 'dir') {
                $this->collectPaths($realDir.'/'.$name, $virtualDir.'/'.$name, $paths);
            }
        }
    }

    /**
     * Run a host call that signals failure with false (and a PHP warning) and turn that into an errno.
     *
     * @template T
     *
     * @param  callable(): (T|false)  $call
     * @return T
     */
    private function host(callable $call, string $operation, string $path, string $error = 'EACCES: permission denied'): mixed
    {
        set_error_handler(fn (): bool => true);

        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }

        return $result !== false ? $result : $this->fail($error, $operation, $path);
    }

    private function fail(string $error, string $operation, string $path): never
    {
        throw new RuntimeException(sprintf("%s, %s '%s'", $error, $operation, $path));
    }
}
