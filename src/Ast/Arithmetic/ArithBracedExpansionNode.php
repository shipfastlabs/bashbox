<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

final readonly class ArithBracedExpansionNode implements ArithExpr
{
    public function __construct(
        public string $content,
    ) {}

    public function getType(): string
    {
        return 'ArithBracedExpansion';
    }
}
