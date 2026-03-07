<?php

declare(strict_types=1);

namespace BashBox\Ast\ParameterOps;

use BashBox\Ast\ArithmeticExpressionNode;

final readonly class SubstringOp implements ParameterOperation
{
    public function __construct(
        public ArithmeticExpressionNode $offset,
        public ?ArithmeticExpressionNode $length = null,
    ) {}

    public function getType(): string
    {
        return 'Substring';
    }
}
