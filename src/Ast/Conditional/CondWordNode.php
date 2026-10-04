<?php

declare(strict_types=1);

namespace BashBox\Ast\Conditional;

use BashBox\Ast\WordNode;

final readonly class CondWordNode implements ConditionalExpressionNode
{
    public function __construct(
        public WordNode $word,
    ) {}
}
