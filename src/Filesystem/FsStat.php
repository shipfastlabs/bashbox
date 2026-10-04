<?php

declare(strict_types=1);

namespace BashBox\Filesystem;

final readonly class FsStat
{
    public function __construct(
        public bool $isFile,
        public bool $isDirectory,
        public bool $isSymbolicLink,
        public int $mode,
        public int $size,
        public int $mtime,
        /** Inode number; equal for every hard link to the same file within one filesystem. */
        public int $ino = 0,
        public int $nlink = 1,
    ) {}
}
