<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

/** $error is the message for division by 0, should it happen */
final readonly class ArithAssignmentNode implements ArithExpr
{
    public function __construct(
        public string $operator,
        public ArithVariableNode|ArithArrayElementNode $target,
        public ArithExpr $value,
        public string $error = '',
    ) {}
}
