<?php

declare(strict_types=1);

namespace BashBox\Filesystem;

use RuntimeException;

/** Holds everything in memory; names and contents count against the quota, so a script cannot grow it without bound. */
final class InMemoryFs implements FileSystemInterface
{
    /**
     * Directory entries: resolved path => inode number. Every ancestor of a stored path is itself stored as a directory.
     *
     * @var array<string, int>
     */
    private array $entries = [];

    /**
     * What a name points at, shared by all its hard links; nlink counts those names.
     *
     * @var array<int, array{type: string, content?: string, target?: string, mode: int, mtime: int, nlink: int}>
     */
    private array $inodes = [];

    private int $nextInode = 1;

    /**
     * @param  array<string, string>  $initialFiles
     */
    public function __construct(array $initialFiles = [], private readonly DiskQuota $diskQuota = new DiskQuota)
    {
        $this->addEntry('/', ['type' => 'directory', 'mode' => 0755]);

        foreach ($initialFiles as $path => $content) {
            $this->writeFile($path, $content);
        }
    }

    public function readFile(string $path): string
    {
        $node = $this->node($this->resolve($path, 'open')) ?? $this->fail('ENOENT: no such file or directory', 'open', $path);

        if ($node['type'] !== 'file') {
            $this->fail('EISDIR: illegal operation on a directory', 'read', $path);
        }

        return $node['content'] ?? '';
    }

    public function writeFile(string $path, string $content): void
    {
        $resolved = $this->resolve($path, 'open');
        $node = $this->node($resolved);

        if ($node === null) {
            $this->ensureParentDirs($resolved);
            $this->addEntry($resolved, ['type' => 'file', 'content' => $content, 'mode' => 0644]);

            return;
        }

        if ($node['type'] === 'directory') {
            $this->fail('EISDIR: illegal operation on a directory', 'open', $path);
        }

        // Write into the inode, so every hard link sees the new content.
        $this->diskQuota->charge(strlen($content) - $this->size($node), 0, $resolved);
        $this->inodes[$this->entries[$resolved]] = ['content' => $content, 'mtime' => time()] + $node;
    }

    public function createExclusive(string $path, bool $directory = false): void
    {
        $operation = $directory ? 'mkdir' : 'open';
        $resolved = $this->resolve($path, $operation, false);

        if (isset($this->entries[$resolved])) {
            $this->fail('EEXIST: file already exists', $operation, $path);
        }

        if (! isset($this->entries[dirname($resolved)])) {
            $this->fail('ENOENT: no such file or directory', $operation, $path);
        }

        $this->addEntry($resolved, $directory ? ['type' => 'directory', 'mode' => 0700] : ['type' => 'file', 'content' => '', 'mode' => 0600]);
    }

    public function appendFile(string $path, string $content): void
    {
        $node = $this->node($this->resolve($path, 'open'));
        $this->writeFile($path, ($node['content'] ?? '').$content);
    }

    public function exists(string $path): bool
    {
        try {
            return isset($this->entries[$this->resolve($path, 'access')]);
        } catch (RuntimeException) {
            return false;
        }
    }

    public function stat(string $path): FsStat
    {
        return $this->statEntry($path, 'stat', true);
    }

    public function lstat(string $path): FsStat
    {
        return $this->statEntry($path, 'lstat', false);
    }

    public function mkdir(string $path, array $options = []): void
    {
        $resolved = $this->resolve($path, 'mkdir', false);
        $recursive = $options['recursive'] ?? false;

        if (isset($this->entries[$resolved])) {
            if ($recursive && ($this->node($this->resolve($path, 'mkdir'))['type'] ?? null) === 'directory') {
                return;
            }

            $this->fail('EEXIST: file already exists', 'mkdir', $path);
        }

        if (! $recursive && ! isset($this->entries[dirname($resolved)])) {
            $this->fail('ENOENT: no such file or directory', 'mkdir', $path);
        }

        $this->ensureParentDirs($resolved);
        $this->addEntry($resolved, ['type' => 'directory', 'mode' => 0755]);
    }

    public function readdir(string $path): array
    {
        return array_map(fn (DirentEntry $direntEntry): string => $direntEntry->name, $this->readdirWithFileTypes($path));
    }

    public function readdirWithFileTypes(string $path): array
    {
        $resolved = $this->resolve($path, 'scandir');
        $node = $this->node($resolved) ?? $this->fail('ENOENT: no such file or directory', 'scandir', $path);

        if ($node['type'] !== 'directory') {
            $this->fail('ENOTDIR: not a directory', 'scandir', $path);
        }

        $entries = [];

        foreach ($this->children($resolved) as $childPath) {
            $type = $this->inodes[$this->entries[$childPath]]['type'];
            $entries[] = new DirentEntry(
                name: basename($childPath),
                isFile: $type === 'file',
                isDirectory: $type === 'directory',
                isSymbolicLink: $type === 'symlink',
            );
        }

        usort($entries, fn (DirentEntry $a, DirentEntry $b): int => strcmp($a->name, $b->name));

        return $entries;
    }

    public function rm(string $path, array $options = []): void
    {
        $resolved = $this->resolve($path, 'rm', false);

        if (! isset($this->entries[$resolved])) {
            if ($options['force'] ?? false) {
                return;
            }

            $this->fail('ENOENT: no such file or directory', 'rm', $path);
        }

        if ($resolved === '/') {
            $this->fail('EPERM: operation not permitted', 'rm', $path);
        }

        $descendants = $this->descendants($resolved);

        if ($descendants !== [] && ! ($options['recursive'] ?? false)) {
            $this->fail('ENOTEMPTY: directory not empty', 'rm', $path);
        }

        foreach ([$resolved, ...$descendants] as $p) {
            $this->removeEntry($p);
        }
    }

    public function cp(string $src, string $dest, array $options = []): void
    {
        $srcResolved = $this->resolve($src, 'cp', false);
        $destResolved = $this->resolve($dest, 'cp');
        $srcNode = $this->node($srcResolved) ?? $this->fail('ENOENT: no such file or directory', 'cp', $src);
        $preserve = $options['preserve'] ?? false;

        if ($srcNode['type'] === 'directory') {
            if (! ($options['recursive'] ?? false)) {
                $this->fail('EISDIR: is a directory', 'cp', $src);
            }

            if ($this->isWithin($destResolved, $srcResolved)) {
                $this->fail('EINVAL: cannot copy a directory into itself', 'cp', $src);
            }

            $this->mkdir($destResolved, ['recursive' => true]);

            foreach ($this->children($srcResolved) as $childPath) {
                $this->cp($childPath, $destResolved.'/'.basename($childPath), $options);
            }

            return;
        }

        $destNode = $this->node($destResolved);

        if ($destNode !== null && $destNode['type'] === 'directory') {
            $this->fail('EISDIR: cannot overwrite directory with non-directory', 'cp', $dest);
        }

        if ($destNode !== null && $srcNode['type'] === 'file') {
            // An existing file is rewritten in place, as cp does, so its hard links see the copy.
            $this->diskQuota->charge($this->size($srcNode) - $this->size($destNode), 0, $destResolved);
            $this->inodes[$this->entries[$destResolved]] = [
                'content' => $srcNode['content'] ?? '',
                'mtime' => $preserve ? $srcNode['mtime'] : time(),
                'mode' => $preserve ? $srcNode['mode'] : $destNode['mode'],
            ] + $destNode;

            return;
        }

        if ($destNode !== null) {
            $this->removeEntry($destResolved);
        }

        $this->ensureParentDirs($destResolved);
        $this->addEntry($destResolved, $preserve ? $srcNode : ['mtime' => time()] + $srcNode);
    }

    /** rename(2): the entry, and everything below it, keeps its inode under the new name. */
    public function mv(string $src, string $dest): void
    {
        $srcResolved = $this->resolve($src, 'rename', false);
        $destResolved = $this->resolve($dest, 'rename', false);
        $inode = $this->entries[$srcResolved] ?? $this->fail('ENOENT: no such file or directory', 'rename', $src);

        // Two names for the same inode (including the same name twice): rename does nothing.
        if ($inode === ($this->entries[$destResolved] ?? null)) {
            return;
        }

        if ($this->isWithin($destResolved, $srcResolved)) {
            $this->fail('EINVAL: invalid argument', 'rename', $src);
        }

        $srcIsDir = $this->inodes[$inode]['type'] === 'directory';
        $destNode = $this->node($destResolved);
        $moved = [$srcResolved, ...$this->descendants($srcResolved)];
        $this->diskQuota->charge(count($moved) * (strlen($destResolved) - strlen($srcResolved)), 0, $destResolved);

        if ($destNode === null) {
            $this->ensureParentDirs($destResolved);
        } else {
            $destIsDir = $destNode['type'] === 'directory';
            $error = match (true) {
                $destIsDir && ! $srcIsDir => 'EISDIR: illegal operation on a directory',
                ! $destIsDir && $srcIsDir => 'ENOTDIR: not a directory',
                $this->descendants($destResolved) !== [] => 'ENOTEMPTY: directory not empty',
                default => null,
            };

            if ($error !== null) {
                $this->fail($error, 'rename', $dest);
            }

            $this->removeEntry($destResolved);
        }

        foreach ($moved as $p) {
            $this->entries[$destResolved.substr($p, strlen($srcResolved))] = $this->entries[$p];
            unset($this->entries[$p]);
        }
    }

    public function resolvePath(string $base, string $path): string
    {
        return VirtualPath::resolve($base, $path);
    }

    public function getAllPaths(): array
    {
        return array_keys($this->entries);
    }

    public function chmod(string $path, int $mode): void
    {
        $this->inodes[$this->inodeOf($path, 'chmod')]['mode'] = $mode;
    }

    public function symlink(string $target, string $linkPath): void
    {
        $resolved = $this->resolve($linkPath, 'symlink', false);

        if (isset($this->entries[$resolved])) {
            $this->fail('EEXIST: file already exists', 'symlink', $linkPath);
        }

        $this->ensureParentDirs($resolved);
        $this->addEntry($resolved, ['type' => 'symlink', 'target' => $target, 'mode' => 0777]);
    }

    public function link(string $existingPath, string $newPath): void
    {
        $inode = $this->entries[$this->resolve($existingPath, 'link', false)] ?? $this->fail('ENOENT: no such file or directory', 'link', $existingPath);
        $newResolved = $this->resolve($newPath, 'link', false);

        if ($this->inodes[$inode]['type'] === 'directory') {
            $this->fail('EPERM: operation not permitted', 'link', $existingPath);
        }

        if (isset($this->entries[$newResolved])) {
            $this->fail('EEXIST: file already exists', 'link', $newPath);
        }

        $this->ensureParentDirs($newResolved);
        $this->diskQuota->charge(strlen($newResolved), 1, $newResolved);
        $this->entries[$newResolved] = $inode;
        $this->inodes[$inode]['nlink']++;
    }

    public function readlink(string $path): string
    {
        $node = $this->node($this->resolve($path, 'readlink', false)) ?? $this->fail('ENOENT: no such file or directory', 'readlink', $path);

        if ($node['type'] !== 'symlink') {
            $this->fail('EINVAL: invalid argument', 'readlink', $path);
        }

        return $node['target'] ?? '';
    }

    public function realpath(string $path): string
    {
        $resolved = $this->resolve($path, 'realpath');

        if (! isset($this->entries[$resolved])) {
            $this->fail('ENOENT: no such file or directory', 'realpath', $path);
        }

        return $resolved;
    }

    public function utimes(string $path, int $mtime): void
    {
        $this->inodes[$this->inodeOf($path, 'utimes')]['mtime'] = $mtime;
    }

    private function statEntry(string $path, string $operation, bool $followLast): FsStat
    {
        $resolved = $this->resolve($path, $operation, $followLast);
        $inode = $this->entries[$resolved] ?? $this->fail('ENOENT: no such file or directory', $operation, $path);
        $node = $this->inodes[$inode];
        $isDirectory = $node['type'] === 'directory';

        return new FsStat(
            isFile: $node['type'] === 'file',
            isDirectory: $isDirectory,
            isSymbolicLink: $node['type'] === 'symlink',
            mode: $node['mode'],
            size: $this->size($node),
            mtime: $node['mtime'],
            ino: $inode,
            // A directory is linked from its parent, its own "." and each subdirectory's "..".
            nlink: $isDirectory ? 2 + count(array_filter(
                $this->children($resolved),
                fn (string $child): bool => $this->inodes[$this->entries[$child]]['type'] === 'directory',
            )) : $node['nlink'],
        );
    }

    /**
     * @return array{type: string, content?: string, target?: string, mode: int, mtime: int, nlink: int}|null
     */
    private function node(string $resolved): ?array
    {
        return isset($this->entries[$resolved]) ? $this->inodes[$this->entries[$resolved]] : null;
    }

    private function inodeOf(string $path, string $operation): int
    {
        return $this->entries[$this->resolve($path, $operation)] ?? $this->fail('ENOENT: no such file or directory', $operation, $path);
    }

    /**
     * @param  array{type: string, content?: string, target?: string, mode: int, mtime?: int, nlink?: int}  $node
     */
    private function addEntry(string $resolved, array $node): void
    {
        $this->diskQuota->charge(strlen($resolved) + $this->size($node), 1, $resolved);
        $this->inodes[$this->nextInode] = ['nlink' => 1] + $node + ['mtime' => time()];
        $this->entries[$resolved] = $this->nextInode++;
    }

    private function removeEntry(string $resolved): void
    {
        $inode = $this->entries[$resolved];
        unset($this->entries[$resolved]);

        $node = $this->inodes[$inode];
        $this->diskQuota->charge(-strlen($resolved) - ($node['nlink'] === 1 ? $this->size($node) : 0), -1, $resolved);

        if ($node['nlink'] === 1) {
            unset($this->inodes[$inode]);
        } else {
            $this->inodes[$inode] = ['nlink' => $node['nlink'] - 1] + $node;
        }
    }

    /** @param array{content?: string, target?: string} $node */
    private function size(array $node): int
    {
        return strlen($node['content'] ?? $node['target'] ?? '');
    }

    private function isWithin(string $path, string $dir): bool
    {
        return str_starts_with($path.'/', rtrim($dir, '/').'/');
    }

    /**
     * Stored paths below the given (resolved) path.
     *
     * @return list<string>
     */
    private function descendants(string $resolved): array
    {
        return array_values(array_filter(array_keys($this->entries), fn (string $p): bool => str_starts_with($p, $resolved.'/')));
    }

    /**
     * Stored paths directly inside the given (resolved) directory.
     *
     * @return list<string>
     */
    private function children(string $dir): array
    {
        $prefix = $dir === '/' ? '/' : $dir.'/';

        return array_values(array_filter(
            array_keys($this->entries),
            fn (string $p): bool => $p !== '/' && str_starts_with($p, $prefix) && ! str_contains(substr($p, strlen($prefix)), '/'),
        ));
    }

    /** Create missing ancestor directories of a resolved path (resolve() has made sure none is a file). */
    private function ensureParentDirs(string $resolved): void
    {
        $dir = dirname($resolved);

        if (! isset($this->entries[$dir])) {
            $this->ensureParentDirs($dir);
            $this->addEntry($dir, ['type' => 'directory', 'mode' => 0755]);
        }
    }

    /** Resolve symlinks in every component (the last only when $followLast), physically: ".." walks up from the link's real location. */
    private function resolve(string $path, string $operation, bool $followLast = true): string
    {
        if (str_contains($path, "\0")) {
            $this->fail('ENOENT: path contains null byte', $operation, $path);
        }

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

            $node = $this->node('/'.implode('/', [...$resolved, $part]));

            if ($pending !== [] && ($node['type'] ?? null) === 'file') {
                $this->fail('ENOTDIR: not a directory', $operation, $path);
            }

            if ($node === null || $node['type'] !== 'symlink' || (! $followLast && $pending === [])) {
                $resolved[] = $part;

                continue;
            }

            if (++$hops > VirtualPath::MAX_SYMLINKS) {
                $this->fail('ELOOP: too many levels of symbolic links', $operation, $path);
            }

            $target = $node['target'] ?? '';

            if (str_starts_with($target, '/')) {
                $resolved = [];
            }

            $pending = [...explode('/', $target), ...$pending];
        }

        return '/'.implode('/', $resolved);
    }

    private function fail(string $error, string $operation, string $path): never
    {
        throw new RuntimeException(sprintf("%s, %s '%s'", $error, $operation, $path));
    }
}
