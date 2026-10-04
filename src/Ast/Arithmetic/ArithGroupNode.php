<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

final readonly class ArithGroupNode implements ArithExpr
{
    public function __construct(
        public ArithExpr $expression,
    ) {}
}
