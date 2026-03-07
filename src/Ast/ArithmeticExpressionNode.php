<?php

declare(strict_types=1);

namespace BashBox\Ast;

use BashBox\Ast\Arithmetic\ArithExpr;

final class ArithmeticExpressionNode implements Node
{
    public function __construct(
        public ArithExpr $expression,
        public ?string $originalText = null,
    ) {}

    public function getType(): string
    {
        return 'ArithmeticExpression';
    }
}
