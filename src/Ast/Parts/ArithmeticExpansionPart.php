<?php

declare(strict_types=1);

namespace BashBox\Ast\Parts;

use BashBox\Ast\ArithmeticExpressionNode;
use BashBox\Ast\WordPart;

final class ArithmeticExpansionPart implements WordPart
{
    public function __construct(
        public ArithmeticExpressionNode $expression,
    ) {}

    public function getType(): string
    {
        return 'ArithmeticExpansion';
    }
}
