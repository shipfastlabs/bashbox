<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

final readonly class ArithNestedNode implements ArithExpr
{
    public function __construct(
        public ArithExpr $expression,
    ) {}

    public function getType(): string
    {
        return 'ArithNested';
    }
}
