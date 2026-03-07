<?php

declare(strict_types=1);

namespace BashBox\Filesystem;

interface FileSystemInterface
{
    public function readFile(string $path): string;

    public function writeFile(string $path, string $content): void;

    public function appendFile(string $path, string $content): void;

    public function exists(string $path): bool;

    public function stat(string $path): FsStat;

    /**
     * @param  array{recursive?: bool}  $options
     */
    public function mkdir(string $path, array $options = []): void;

    /**
     * @return list<string>
     */
    public function readdir(string $path): array;

    /**
     * @return list<DirentEntry>
     */
    public function readdirWithFileTypes(string $path): array;

    /**
     * @param  array{recursive?: bool, force?: bool}  $options
     */
    public function rm(string $path, array $options = []): void;

    /**
     * @param  array{recursive?: bool, preserve?: bool}  $options
     */
    public function cp(string $src, string $dest, array $options = []): void;

    public function mv(string $src, string $dest): void;

    public function resolvePath(string $base, string $path): string;

    /**
     * @return list<string>
     */
    public function getAllPaths(): array;

    public function chmod(string $path, int $mode): void;

    public function symlink(string $target, string $linkPath): void;

    public function link(string $existingPath, string $newPath): void;

    public function readlink(string $path): string;

    public function lstat(string $path): FsStat;

    public function realpath(string $path): string;

    public function utimes(string $path, int $mtime): void;
}
