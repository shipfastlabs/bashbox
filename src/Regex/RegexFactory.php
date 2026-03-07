<?php

declare(strict_types=1);

namespace BashBox\Regex;

final class RegexFactory
{
    /**
     * Create a regex engine instance.
     *
     * Returns SafePcreRegex by default. RE2 FFI support can be added later.
     */
    public static function create(): RegexInterface
    {
        return new SafePcreRegex;
    }
}
