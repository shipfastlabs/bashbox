<?php

declare(strict_types=1);

namespace BashBox\Ast\Conditional;

final class CondGroupNode implements ConditionalExpressionNode
{
    public function __construct(
        public ConditionalExpressionNode $expression,
    ) {}

    public function getType(): string
    {
        return 'CondGroup';
    }
}
