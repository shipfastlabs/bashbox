<?php

declare(strict_types=1);

namespace BashBox\Ast\Conditional;

use BashBox\Ast\WordNode;

final class CondBinaryNode implements ConditionalExpressionNode
{
    public function __construct(
        public string $operator,
        public WordNode $left,
        public WordNode $right,
    ) {}

    public function getType(): string
    {
        return 'CondBinary';
    }
}
