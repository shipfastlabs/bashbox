<?php

declare(strict_types=1);

namespace BashBox\Parser;

final readonly class Token
{
    /** @param bool $quoted on HEREDOC_CONTENT: whether the delimiter was quoted, so the body isn't expanded */
    public function __construct(
        public TokenType $type,
        public string $value,
        public int $start,
        public int $end,
        public int $line,
        public bool $quoted = false,
    ) {}
}
