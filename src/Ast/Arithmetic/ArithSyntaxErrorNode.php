<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

final readonly class ArithSyntaxErrorNode implements ArithExpr
{
    public function __construct(
        public string $errorToken,
        public string $message,
    ) {}

    public function getType(): string
    {
        return 'ArithSyntaxError';
    }
}
