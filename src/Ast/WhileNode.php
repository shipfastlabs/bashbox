<?php

declare(strict_types=1);

namespace BashBox\Ast;

final class WhileNode implements CompoundCommandNode
{
    /**
     * @param  list<StatementNode>  $condition
     * @param  list<StatementNode>  $body
     * @param  list<RedirectionNode>  $redirections
     */
    public function __construct(
        public array $condition,
        public array $body,
        public array $redirections = [],
        public ?int $line = null,
    ) {}

    public function getType(): string
    {
        return 'While';
    }
}
