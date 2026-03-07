<?php

declare(strict_types=1);

namespace BashBox\Regex;

interface RegexInterface
{
    public function test(string $pattern, string $subject): bool;

    /** @return array<int, string>|null */
    public function match(string $pattern, string $subject): ?array;

    public function replace(string $pattern, string $replacement, string $subject): string;

    /** @return list<string> */
    public function split(string $pattern, string $subject, int $limit = -1): array;
}
