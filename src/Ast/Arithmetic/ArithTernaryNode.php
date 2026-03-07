<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

final class ArithTernaryNode implements ArithExpr
{
    public function __construct(
        public ArithExpr $condition,
        public ArithExpr $consequent,
        public ArithExpr $alternate,
    ) {}

    public function getType(): string
    {
        return 'ArithTernary';
    }
}
