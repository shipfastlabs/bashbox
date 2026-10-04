<?php

declare(strict_types=1);

namespace BashBox\Ast\Conditional;

final readonly class CondOrNode implements ConditionalExpressionNode
{
    public function __construct(
        public ConditionalExpressionNode $left,
        public ConditionalExpressionNode $right,
    ) {}
}
