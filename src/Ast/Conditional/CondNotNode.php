<?php

declare(strict_types=1);

namespace BashBox\Ast\Conditional;

final readonly class CondNotNode implements ConditionalExpressionNode
{
    public function __construct(
        public ConditionalExpressionNode $operand,
    ) {}
}
