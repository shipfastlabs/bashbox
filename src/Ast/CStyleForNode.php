<?php

declare(strict_types=1);

namespace BashBox\Ast;

final class CStyleForNode implements CompoundCommandNode
{
    /**
     * @param  list<StatementNode>  $body
     * @param  list<RedirectionNode>  $redirections
     */
    public function __construct(
        public ?ArithmeticExpressionNode $init,
        public ?ArithmeticExpressionNode $condition,
        public ?ArithmeticExpressionNode $update,
        public array $body,
        public array $redirections = [],
        public ?int $line = null,
    ) {}

    public function getType(): string
    {
        return 'CStyleFor';
    }
}
