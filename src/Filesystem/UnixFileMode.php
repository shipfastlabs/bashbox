<?php

declare(strict_types=1);

namespace BashBox\Filesystem;

final class UnixFileMode
{
    public const int PERMISSIONS_MASK = 0o777;

    public const int FULL_PERMISSIONS = 0o777;

    public static function permissions(int $mode): int
    {
        return $mode & self::PERMISSIONS_MASK;
    }

    public static function type(int $mode): ?UnixFileType
    {
        return UnixFileType::fromMode($mode);
    }

    public static function isDirectory(int $mode): bool
    {
        return self::type($mode) === UnixFileType::Directory;
    }

    public static function isRegularFile(int $mode): bool
    {
        return self::type($mode) === UnixFileType::RegularFile;
    }

    public static function isSymbolicLink(int $mode): bool
    {
        return self::type($mode) === UnixFileType::SymbolicLink;
    }
}
