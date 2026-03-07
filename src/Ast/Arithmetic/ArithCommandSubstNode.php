<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

final readonly class ArithCommandSubstNode implements ArithExpr
{
    public function __construct(
        public string $command,
    ) {}

    public function getType(): string
    {
        return 'ArithCommandSubst';
    }
}
