<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

final class ArithVariableNode implements ArithExpr
{
    public function __construct(
        public string $name,
        public bool $hasDollarPrefix = false,
    ) {}

    public function getType(): string
    {
        return 'ArithVariable';
    }
}
