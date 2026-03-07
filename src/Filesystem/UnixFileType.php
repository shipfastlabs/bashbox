<?php

declare(strict_types=1);

namespace BashBox\Filesystem;

enum UnixFileType: int
{
    case Directory = 0o040000;
    case RegularFile = 0o100000;
    case SymbolicLink = 0o120000;

    public const int MASK = 0o170000;

    public static function fromMode(int $mode): ?self
    {
        return self::tryFrom($mode & self::MASK);
    }
}
