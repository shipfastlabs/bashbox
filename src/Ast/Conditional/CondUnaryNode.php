<?php

declare(strict_types=1);

namespace BashBox\Ast\Conditional;

use BashBox\Ast\WordNode;

final class CondUnaryNode implements ConditionalExpressionNode
{
    public function __construct(
        public string $operator,
        public WordNode $operand,
    ) {}

    public function getType(): string
    {
        return 'CondUnary';
    }
}
