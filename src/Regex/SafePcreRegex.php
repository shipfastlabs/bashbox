<?php

declare(strict_types=1);

namespace BashBox\Regex;

use BashBox\Exceptions\BashException;

final readonly class SafePcreRegex implements RegexInterface
{
    private const array DELIMITER_CHARS = ['/', '#', '~', '!', '@', '%'];

    public function __construct(
        private int $backtrackLimit = 10_000,
        private int $recursionLimit = 5_000,
        private int $maxPatternLength = 10_000,
    ) {}

    public function test(string $pattern, string $subject): bool
    {
        $pattern = $this->ensureDelimited($pattern);
        $this->validatePatternLength($pattern);

        return $this->withLimits(function () use ($pattern, $subject): bool {
            $result = @preg_match($pattern, $subject);

            $this->checkPcreError($pattern);

            return $result === 1;
        });
    }

    /** @return array<int, string>|null */
    public function match(string $pattern, string $subject): ?array
    {
        $pattern = $this->ensureDelimited($pattern);
        $this->validatePatternLength($pattern);

        return $this->withLimits(function () use ($pattern, $subject): ?array {
            $matches = [];
            $result = @preg_match($pattern, $subject, $matches);

            $this->checkPcreError($pattern);

            if ($result === 0) {
                return null;
            }

            /** @var array<int, string> $matches */
            return $matches;
        });
    }

    public function replace(string $pattern, string $replacement, string $subject): string
    {
        $pattern = $this->ensureDelimited($pattern);
        $this->validatePatternLength($pattern);

        return $this->withLimits(function () use ($pattern, $replacement, $subject): string {
            $result = @preg_replace($pattern, $replacement, $subject);

            $this->checkPcreError($pattern);

            if ($result === null) {
                throw new BashException('Regex replace failed for pattern: '.$pattern);
            }

            return $result;
        });
    }

    /** @return list<string> */
    public function split(string $pattern, string $subject, int $limit = -1): array
    {
        $pattern = $this->ensureDelimited($pattern);
        $this->validatePatternLength($pattern);

        return $this->withLimits(function () use ($pattern, $subject, $limit): array {
            $result = @preg_split($pattern, $subject, $limit);

            $this->checkPcreError($pattern);

            if ($result === false) {
                throw new BashException('Regex split failed for pattern: '.$pattern);
            }

            /** @var list<string> $result */
            return $result;
        });
    }

    private function validatePatternLength(string $pattern): void
    {
        if (mb_strlen($pattern) > $this->maxPatternLength) {
            throw new BashException(
                sprintf('Regex pattern exceeds maximum length of %d characters', $this->maxPatternLength)
            );
        }
    }

    private function ensureDelimited(string $pattern): string
    {
        if ($pattern === '') {
            return '//';
        }

        $firstChar = $pattern[0];

        foreach (self::DELIMITER_CHARS as $delimiter) {
            if ($firstChar === $delimiter) {
                return $pattern;
            }
        }

        // Choose a delimiter that does not appear in the pattern
        foreach (self::DELIMITER_CHARS as $delimiter) {
            if (! str_contains($pattern, $delimiter)) {
                return $delimiter.$pattern.$delimiter;
            }
        }

        // Fallback: escape forward slashes in the pattern
        $escaped = str_replace('/', '\\/', $pattern);

        return '/'.$escaped.'/';
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withLimits(callable $callback): mixed
    {
        $previousBacktrack = ini_get('pcre.backtrack_limit');
        $previousRecursion = ini_get('pcre.recursion_limit');

        ini_set('pcre.backtrack_limit', (string) $this->backtrackLimit);
        ini_set('pcre.recursion_limit', (string) $this->recursionLimit);

        try {
            return $callback();
        } finally {
            ini_set('pcre.backtrack_limit', $previousBacktrack !== false ? $previousBacktrack : '1000000');
            ini_set('pcre.recursion_limit', $previousRecursion !== false ? $previousRecursion : '100000');
        }
    }

    private function checkPcreError(string $pattern): void
    {
        $error = preg_last_error();

        if ($error === PREG_NO_ERROR) {
            return;
        }

        $message = match ($error) {
            PREG_INTERNAL_ERROR => 'Internal PCRE error',
            PREG_BACKTRACK_LIMIT_ERROR => 'Backtrack limit exhausted (possible catastrophic backtracking)',
            PREG_RECURSION_LIMIT_ERROR => 'Recursion limit exhausted',
            PREG_BAD_UTF8_ERROR => 'Malformed UTF-8 data',
            PREG_BAD_UTF8_OFFSET_ERROR => 'Invalid UTF-8 offset',
            PREG_JIT_STACKLIMIT_ERROR => 'JIT stack limit exhausted',
            default => 'Unknown PCRE error (code: '.$error.')',
        };

        throw new BashException(sprintf('Regex error for pattern %s: %s', $pattern, $message));
    }
}
