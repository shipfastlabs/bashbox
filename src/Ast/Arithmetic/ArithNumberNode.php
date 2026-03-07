<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

final readonly class ArithNumberNode implements ArithExpr
{
    public function __construct(
        public int|float $value,
    ) {}

    public function getType(): string
    {
        return 'ArithNumber';
    }
}
