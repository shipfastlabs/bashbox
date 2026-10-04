<?php

declare(strict_types=1);

namespace BashBox\Ast\Conditional;

use BashBox\Ast\WordNode;

final readonly class CondUnaryNode implements ConditionalExpressionNode
{
    public function __construct(
        public string $operator,
        public WordNode $operand,
    ) {}
}
