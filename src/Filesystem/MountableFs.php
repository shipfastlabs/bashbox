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
        private readonly FileSystemInterface $fileSystem,
    ) {}

    public function mount(string $mountPoint, FileSystemInterface $fileSystem): void
    {
        $mountPoint = VirtualPath::normalize($mountPoint);

        if ($mountPoint === '/') {
            throw new RuntimeException("EINVAL: cannot mount over the root filesystem, mount '/'");
        }

        $this->mounts[$mountPoint] = $fileSystem;
    }

    public function unmount(string $mountPoint): void
    {
        $mountPoint = VirtualPath::normalize($mountPoint);
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

    public function createExclusive(string $path, bool $directory = false): void
    {
        [$fs, $innerPath] = $this->resolve($path);
        $fs->createExclusive($innerPath, $directory);
    }

    public function appendFile(string $path, string $content): void
    {
        [$fs, $innerPath] = $this->resolve($path);
        $fs->appendFile($innerPath, $content);
    }

    public function exists(string $path): bool
    {
        [$fs, $innerPath] = $this->resolve($path);

        return $fs->exists($innerPath);
    }

    public function stat(string $path): FsStat
    {
        [$fs, $innerPath] = $this->resolve($path);

        return $fs->stat($innerPath);
    }

    public function lstat(string $path): FsStat
    {
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
        return array_map(fn (DirentEntry $direntEntry): string => $direntEntry->name, $this->readdirWithFileTypes($path));
    }

    public function readdirWithFileTypes(string $path): array
    {
        $normalized = VirtualPath::normalize($path);
        [$fs, $innerPath] = $this->resolve($path);

        $entriesMap = array_column($fs->readdirWithFileTypes($innerPath), null, 'name');

        // Mount points below this directory show up as directories in it
        $prefix = $normalized === '/' ? '/' : $normalized.'/';

        foreach (array_keys($this->mounts) as $mp) {
            if (str_starts_with($mp, $prefix)) {
                $name = explode('/', substr($mp, strlen($prefix)))[0];
                $entriesMap[$name] ??= new DirentEntry(name: $name, isFile: false, isDirectory: true, isSymbolicLink: false);
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
        $fsStat = $srcFs->stat($srcInner);

        if ($fsStat->isFile) {
            $destFs->writeFile($destInner, $srcFs->readFile($srcInner));
            $destFs->chmod($destInner, $fsStat->mode);

            if ($options['preserve'] ?? false) {
                $destFs->utimes($destInner, $fsStat->mtime);
            }

            return;
        }

        if (! $recursive) {
            throw new RuntimeException(sprintf("EISDIR: is a directory, cp '%s'", $src));
        }

        $destFs->mkdir($destInner, ['recursive' => true]);

        foreach ($srcFs->readdir($srcInner) as $child) {
            $this->cp(rtrim($src, '/').'/'.$child, rtrim($dest, '/').'/'.$child, $options);
        }
    }

    public function mv(string $src, string $dest): void
    {
        [$srcFs, $srcInner] = $this->resolve($src);
        [$destFs, $destInner] = $this->resolve($dest);

        if ($srcFs === $destFs) {
            $srcFs->mv($srcInner, $destInner);

            return;
        }

        $this->cp($src, $dest, ['recursive' => true, 'preserve' => true]);
        $this->rm($src, ['recursive' => true]);
    }

    public function resolvePath(string $base, string $path): string
    {
        return VirtualPath::resolve($base, $path);
    }

    public function getAllPaths(): array
    {
        $allPaths = $this->fileSystem->getAllPaths();

        foreach ($this->mounts as $mp => $fs) {
            $mountedPaths = $fs->getAllPaths();

            foreach ($mountedPaths as $mountedPath) {
                $allPaths[] = $mountedPath === '/' ? $mp : $mp.$mountedPath;
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
        [$fs, $innerPath, $mountPoint] = $this->resolve($path);

        return rtrim($mountPoint.$fs->realpath($innerPath), '/') ?: '/';
    }

    public function utimes(string $path, int $mtime): void
    {
        [$fs, $innerPath] = $this->resolve($path);
        $fs->utimes($innerPath, $mtime);
    }

    /**
     * Route a path to the filesystem of its longest matching mount point ('' for the default filesystem).
     *
     * @return array{0: FileSystemInterface, 1: string, 2: string}
     */
    private function resolve(string $path): array
    {
        $normalized = VirtualPath::normalize($path);
        $best = '';

        foreach (array_keys($this->mounts) as $mp) {
            if (strlen($mp) > strlen($best) && ($normalized === $mp || str_starts_with($normalized, $mp.'/'))) {
                $best = $mp;
            }
        }

        if ($best === '') {
            return [$this->fileSystem, $normalized, ''];
        }

        return [$this->mounts[$best], substr($normalized, strlen($best)) ?: '/', $best];
    }
}
