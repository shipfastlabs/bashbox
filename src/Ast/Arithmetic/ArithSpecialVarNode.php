<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

final readonly class ArithSpecialVarNode implements ArithExpr
{
    public function __construct(
        public string $name,
    ) {}

    public function getType(): string
    {
        return 'ArithSpecialVar';
    }
}
