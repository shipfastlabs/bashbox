<?php

declare(strict_types=1);

namespace BashBox\Parser;

final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $value,
        public int $start,
        public int $end,
        public int $line,
        public int $column,
        public bool $quoted = false,
        public bool $singleQuoted = false,
    ) {}
}
