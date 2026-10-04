<?php

declare(strict_types=1);

namespace BashBox\Filesystem;

final class VirtualPath
{
    /** Linux MAXSYMLINKS: symlinks followed per lookup before giving up with ELOOP. */
    public const int MAX_SYMLINKS = 40;

    /** Lexical, like bash's own path handling: ".." never climbs above "/" and symlinks are not consulted. */
    public static function normalize(string $path): string
    {
        $resolved = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($resolved);
            } elseif ($part !== '' && $part !== '.') {
                $resolved[] = $part;
            }
        }

        return '/'.implode('/', $resolved);
    }

    public static function resolve(string $base, string $path): string
    {
        return self::normalize(str_starts_with($path, '/') ? $path : $base.'/'.$path);
    }
}
