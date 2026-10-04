<?php

declare(strict_types=1);

namespace BashBox\Filesystem;

use RuntimeException;

/** How much a filesystem may hold, in bytes and in entries; growing past either fails with ENOSPC. */
final class DiskQuota
{
    public const int DEFAULT_BYTES = 64 * 1024 * 1024;

    public const int DEFAULT_FILES = 10_000;

    private int $bytes = 0;

    private int $files = 0;

    public function __construct(
        public readonly int $maxBytes = self::DEFAULT_BYTES,
        public readonly int $maxFiles = self::DEFAULT_FILES,
    ) {}

    /** Record a change in usage (negative when space is freed); growth past a cap records nothing and throws. */
    public function charge(int $bytes, int $files, string $path): void
    {
        if (($bytes > 0 && $this->bytes + $bytes > $this->maxBytes) || ($files > 0 && $this->files + $files > $this->maxFiles)) {
            throw new RuntimeException(sprintf("ENOSPC: no space left on device, write '%s'", $path));
        }

        $this->bytes += $bytes;
        $this->files += $files;
    }
}
