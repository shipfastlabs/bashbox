<?php

declare(strict_types=1);

namespace BashBox\Ast;

use BashBox\Ast\Conditional\ConditionalExpressionNode;

final class ConditionalCommandNode implements CompoundCommandNode
{
    /**
     * @param  list<RedirectionNode>  $redirections
     */
    public function __construct(
        public ConditionalExpressionNode $expression,
        public array $redirections = [],
        public ?int $line = null,
    ) {}

    public function getType(): string
    {
        return 'ConditionalCommand';
    }
}
