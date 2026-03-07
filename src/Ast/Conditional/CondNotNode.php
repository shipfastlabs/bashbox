<?php

declare(strict_types=1);

namespace BashBox\Ast\Conditional;

final class CondNotNode implements ConditionalExpressionNode
{
    public function __construct(
        public ConditionalExpressionNode $operand,
    ) {}

    public function getType(): string
    {
        return 'CondNot';
    }
}
