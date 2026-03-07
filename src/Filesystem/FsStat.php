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
    ) {}
}
