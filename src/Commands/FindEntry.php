<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\Filesystem\FsStat;

/** A file find has reached: where it is, how find shows it, and its lstat(). */
final readonly class FindEntry
{
    public function __construct(
        public string $path,
        public string $display,
        public string $start,
        public string $relative,
        public int $depth,
        /** d, f or l */
        public string $type,
        public FsStat $stat,
    ) {}
}
