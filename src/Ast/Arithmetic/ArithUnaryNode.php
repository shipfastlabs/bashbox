<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

final class ArithUnaryNode implements ArithExpr
{
    public function __construct(
        public string $operator,
        public ArithExpr $operand,
        public bool $prefix = true,
    ) {}

    public function getType(): string
    {
        return 'ArithUnary';
    }
}
