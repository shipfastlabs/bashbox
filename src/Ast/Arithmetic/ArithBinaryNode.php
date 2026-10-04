<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

/** $error is the message for division by 0 (or a negative exponent), should it happen */
final readonly class ArithBinaryNode implements ArithExpr
{
    public function __construct(
        public string $operator,
        public ArithExpr $left,
        public ArithExpr $right,
        public string $error = '',
    ) {}
}
