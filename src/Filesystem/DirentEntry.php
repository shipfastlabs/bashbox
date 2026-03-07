<?php

declare(strict_types=1);

namespace BashBox\Filesystem;

final readonly class DirentEntry
{
    public function __construct(
        public string $name,
        public bool $isFile,
        public bool $isDirectory,
        public bool $isSymbolicLink,
    ) {}
}
