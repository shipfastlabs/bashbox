<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

final readonly class ArithBinaryNode implements ArithExpr
{
    public function __construct(
        public string $operator,
        public ArithExpr $left,
        public ArithExpr $right,
    ) {}

    public function getType(): string
    {
        return 'ArithBinary';
    }
}
