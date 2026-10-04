<?php

declare(strict_types=1);

namespace BashBox\Regex;

/** Where commands run user-supplied regexes: under fixed PCRE limits, with a failed match raised instead of read as "no match". */
final class SafePcreRegex
{
    public const int BACKTRACK_LIMIT = 1_000_000;

    public const int RECURSION_LIMIT = 100_000;

    /**
     * Runs preg_match() under the limits.
     *
     * @param  array<array-key, mixed>|null  $matches
     * @param  0|256|512|768  $flags
     *
     * @param-out array<array-key, mixed> $matches
     *
     * @throws RegexException
     */
    public static function match(string $regex, string $subject, ?array &$matches = null, int $flags = 0, int $offset = 0): bool
    {
        $previous = self::limit();
        $result = preg_match($regex, $subject, $matches, $flags, $offset);
        self::limit($previous);

        return self::check($result) === 1;
    }

    /**
     * Returns every match of the whole regex.
     *
     * @return list<string>
     *
     * @throws RegexException
     */
    public static function matchAll(string $regex, string $subject): array
    {
        $previous = self::limit();
        $result = preg_match_all($regex, $subject, $matches);
        self::limit($previous);
        self::check($result);

        return $matches[0];
    }

    /**
     * Sets the limits, since the host's ini may allow far more backtracking, or puts back the given values.
     *
     * @param  array{string|false, string|false}|null  $restore
     * @return array{string|false, string|false}
     */
    private static function limit(?array $restore = null): array
    {
        return [
            ini_set('pcre.backtrack_limit', (string) ($restore[0] ?? self::BACKTRACK_LIMIT)),
            ini_set('pcre.recursion_limit', (string) ($restore[1] ?? self::RECURSION_LIMIT)),
        ];
    }

    private static function check(int|false $result): int
    {
        return $result === false ? throw new RegexException('regex match failed: '.preg_last_error_msg()) : $result;
    }
}
