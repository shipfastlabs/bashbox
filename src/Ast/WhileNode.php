<?php

declare(strict_types=1);

namespace BashBox\Ast;

final readonly class WhileNode implements CompoundCommandNode
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
        public int $line = 0,
    ) {}
}
