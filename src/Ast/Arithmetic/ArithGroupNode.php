<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

final class ArithGroupNode implements ArithExpr
{
    public function __construct(
        public ArithExpr $expression,
    ) {}

    public function getType(): string
    {
        return 'ArithGroup';
    }
}
