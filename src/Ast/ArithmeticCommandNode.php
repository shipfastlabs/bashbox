<?php

declare(strict_types=1);

namespace BashBox\Ast;

final class ArithmeticCommandNode implements CompoundCommandNode
{
    /**
     * @param  list<RedirectionNode>  $redirections
     */
    public function __construct(
        public ArithmeticExpressionNode $expression,
        public array $redirections = [],
        public ?int $line = null,
    ) {}

    public function getType(): string
    {
        return 'ArithmeticCommand';
    }
}
