<?php

declare(strict_types=1);

namespace BashBox\Filesystem;

use RuntimeException;

/**
 * Keeps /dev in memory over any filesystem, so nothing under it (/dev/null, the /dev/fd files of
 * process substitution) reaches the wrapped one. /dev/null reads empty and discards writes, and
 * symlinks are followed across both, so a link to /dev/null behaves as /dev/null.
 */
final readonly class DevFs implements FileSystemInterface
{
    private const string NULL = '/dev/null';

    private const string DIR = '/dev';

    private InMemoryFs $inMemoryFs;

    public function __construct(private FileSystemInterface $fileSystem, DiskQuota $diskQuota = new DiskQuota)
    {
        $this->inMemoryFs = new InMemoryFs([], $diskQuota);
        $this->inMemoryFs->mkdir(self::DIR);
    }

    public function readFile(string $path): string
    {
        [$fs, $path] = $this->route($path, 'open');

        return $this->isNull($path, 'open') ? '' : $fs->readFile($path);
    }

    public function writeFile(string $path, string $content): void
    {
        [$fs, $path] = $this->route($path, 'open');

        if (! $this->isNull($path, 'open')) {
            $fs->writeFile($path, $content);
        }
    }

    public function createExclusive(string $path, bool $directory = false): void
    {
        $operation = $directory ? 'mkdir' : 'open';
        [$fs, $path] = $this->route($path, $operation, false);
        $this->deny($path, 'EEXIST: file already exists', $operation);
        $fs->createExclusive($path, $directory);
    }

    public function appendFile(string $path, string $content): void
    {
        [$fs, $path] = $this->route($path, 'open');

        if (! $this->isNull($path, 'open')) {
            $fs->appendFile($path, $content);
        }
    }

    public function exists(string $path): bool
    {
        try {
            [$fs, $path] = $this->route($path, 'access');

            if ($this->isNull($path, 'access')) {
                return true;
            }

            return $fs->exists($path);
        } catch (RuntimeException) {
            return false;
        }
    }

    public function stat(string $path): FsStat
    {
        [$fs, $path] = $this->route($path, 'stat');

        return $this->isNull($path, 'stat') ? $this->nullStat() : $fs->stat($path);
    }

    public function lstat(string $path): FsStat
    {
        [$fs, $path] = $this->route($path, 'lstat', false);

        return $this->isNull($path, 'lstat') ? $this->nullStat() : $fs->lstat($path);
    }

    public function mkdir(string $path, array $options = []): void
    {
        [$fs, $path] = $this->route($path, 'mkdir', false);
        $this->deny($path, 'EEXIST: file already exists', 'mkdir');
        $fs->mkdir($path, $options);
    }

    public function readdir(string $path): array
    {
        return array_map(fn (DirentEntry $direntEntry): string => $direntEntry->name, $this->readdirWithFileTypes($path));
    }

    public function readdirWithFileTypes(string $path): array
    {
        [$fs, $path] = $this->route($path, 'scandir');
        $this->deny($path, 'ENOTDIR: not a directory', 'scandir');
        $entries = array_column($fs->readdirWithFileTypes($path), null, 'name');

        if ($path === self::DIR) {
            $entries['null'] = new DirentEntry('null', false, false, false);
        }

        ksort($entries, SORT_STRING);

        return array_values($entries);
    }

    public function rm(string $path, array $options = []): void
    {
        [$fs, $path] = $this->route($path, 'rm', false);
        $this->deny($path, 'EACCES: permission denied', 'rm', true);
        $fs->rm($path, $options);
    }

    public function cp(string $src, string $dest, array $options = []): void
    {
        [$srcFs, $srcPath] = $this->route($src, 'cp');
        [$destFs, $destPath] = $this->route($dest, 'cp');

        if ($this->isNull($srcPath, 'cp') || $this->isNull($destPath, 'cp')) {
            $this->writeFile($dest, $this->readFile($src));

            return;
        }

        if ($srcFs === $destFs) {
            $srcFs->cp($srcPath, $destPath, $options);

            return;
        }

        $stat = $srcFs->stat($srcPath);

        if (! $stat->isDirectory) {
            $destFs->writeFile($destPath, $srcFs->readFile($srcPath));

            if ($options['preserve'] ?? false) {
                $destFs->chmod($destPath, $stat->mode);
                $destFs->utimes($destPath, $stat->mtime);
            }

            return;
        }

        if (! ($options['recursive'] ?? false)) {
            throw new RuntimeException(sprintf("EISDIR: is a directory, cp '%s'", $src));
        }

        $destFs->mkdir($destPath, ['recursive' => true]);

        foreach ($srcFs->readdir($srcPath) as $child) {
            $this->cp($srcPath.'/'.$child, $destPath.'/'.$child, $options);
        }
    }

    public function mv(string $src, string $dest): void
    {
        [$srcFs, $srcPath] = $this->route($src, 'rename', false);
        [$destFs, $destPath] = $this->route($dest, 'rename', false);
        $this->deny($srcPath, 'EACCES: permission denied', 'rename', true);
        $this->deny($destPath, 'EACCES: permission denied', 'rename', true);

        if ($srcFs === $destFs) {
            $srcFs->mv($srcPath, $destPath);

            return;
        }

        $this->cp($srcPath, $destPath, ['recursive' => true, 'preserve' => true]);
        $srcFs->rm($srcPath, ['recursive' => true]);
    }

    public function resolvePath(string $base, string $path): string
    {
        return $this->fileSystem->resolvePath($base, $path);
    }

    public function getAllPaths(): array
    {
        $outside = array_filter($this->fileSystem->getAllPaths(), fn (string $path): bool => ! $this->inDev($path));

        return [...$outside, ...array_diff($this->inMemoryFs->getAllPaths(), ['/']), self::NULL];
    }

    public function chmod(string $path, int $mode): void
    {
        [$fs, $path] = $this->route($path, 'chmod');
        $this->deny($path, 'EPERM: operation not permitted', 'chmod');
        $fs->chmod($path, $mode);
    }

    public function symlink(string $target, string $linkPath): void
    {
        [$fs, $linkPath] = $this->route($linkPath, 'symlink', false);
        $this->deny($linkPath, 'EEXIST: file already exists', 'symlink');
        $fs->symlink($target, $linkPath);
    }

    public function link(string $existingPath, string $newPath): void
    {
        [$existingFs, $existingPath] = $this->route($existingPath, 'link', false);
        [$newFs, $newPath] = $this->route($newPath, 'link', false);
        $this->deny($existingPath, 'EPERM: operation not permitted', 'link');
        $this->deny($newPath, 'EEXIST: file already exists', 'link');

        if ($existingFs !== $newFs) {
            throw new RuntimeException(sprintf("EXDEV: cross-device link not permitted, link '%s' -> '%s'", $existingPath, $newPath));
        }

        $existingFs->link($existingPath, $newPath);
    }

    public function readlink(string $path): string
    {
        [$fs, $path] = $this->route($path, 'readlink', false);
        $this->deny($path, 'EINVAL: invalid argument', 'readlink');

        return $fs->readlink($path);
    }

    public function realpath(string $path): string
    {
        [$fs, $path] = $this->route($path, 'realpath');

        return $this->isNull($path, 'realpath') ? self::NULL : $fs->realpath($path);
    }

    public function utimes(string $path, int $mtime): void
    {
        [$fs, $path] = $this->route($path, 'utimes');

        if (! $this->isNull($path, 'utimes')) {
            $fs->utimes($path, $mtime);
        }
    }

    /**
     * The layer holding a path, after following symlinks of both layers (the last only when $followLast),
     * and the path to hand it: resolved for /dev, or as given while only the wrapped filesystem's links
     * were followed, so it resolves (and contains) them itself and reports errors on the caller's path.
     *
     * @return array{FileSystemInterface, string}
     */
    private function route(string $path, string $operation, bool $followLast = true): array
    {
        $pending = explode('/', VirtualPath::normalize($path));
        $resolved = [];
        $viaDev = false;
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

            $resolved[] = $part;
            $current = '/'.implode('/', $resolved);

            if ($pending === [] && ! $followLast) {
                continue;
            }

            if (! $this->isLink($current)) {
                continue;
            }

            if (++$hops > VirtualPath::MAX_SYMLINKS) {
                throw new RuntimeException(sprintf("ELOOP: too many levels of symbolic links, %s '%s'", $operation, $path));
            }

            $layer = $this->layer($current);
            $viaDev = $viaDev || $layer === $this->inMemoryFs;
            $target = $layer->readlink($current);
            array_pop($resolved);

            if (str_starts_with($target, '/')) {
                $resolved = [];
            }

            $pending = [...explode('/', $target), ...$pending];
        }

        $resolved = '/'.implode('/', $resolved);
        $layer = $this->layer($resolved);

        return [$layer, $layer === $this->inMemoryFs || $viaDev ? $resolved : $path];
    }

    private function isLink(string $path): bool
    {
        try {
            return $this->layer($path)->lstat($path)->isSymbolicLink;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function layer(string $path): FileSystemInterface
    {
        return $this->inDev($path) ? $this->inMemoryFs : $this->fileSystem;
    }

    private function inDev(string $path): bool
    {
        return $path === self::DIR || str_starts_with($path, self::DIR.'/');
    }

    /** Whether a resolved path is /dev/null; a path through it (/dev/null/x) is ENOTDIR. */
    private function isNull(string $path, string $operation): bool
    {
        if (str_starts_with($path, self::NULL.'/')) {
            throw new RuntimeException(sprintf("ENOTDIR: not a directory, %s '%s'", $operation, $path));
        }

        return $path === self::NULL;
    }

    /** /dev/null, and with $orDir /dev itself, can't be the target of $operation. */
    private function deny(string $path, string $error, string $operation, bool $orDir = false): void
    {
        if ($this->isNull($path, $operation) || ($orDir && $path === self::DIR)) {
            throw new RuntimeException(sprintf("%s, %s '%s'", $error, $operation, $path));
        }
    }

    private function nullStat(): FsStat
    {
        return new FsStat(isFile: false, isDirectory: false, isSymbolicLink: false, mode: 0o666, size: 0, mtime: 0);
    }
}
