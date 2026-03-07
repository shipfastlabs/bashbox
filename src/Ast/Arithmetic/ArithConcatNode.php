<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

final class ArithConcatNode implements ArithExpr
{
    /**
     * @param  list<ArithExpr>  $parts
     */
    public function __construct(
        public array $parts,
    ) {}

    public function getType(): string
    {
        return 'ArithConcat';
    }
}
