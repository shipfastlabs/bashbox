<?php

declare(strict_types=1);

namespace BashBox\Ast;

final class IfNode implements CompoundCommandNode
{
    /**
     * @param  list<IfClause>  $clauses
     * @param  list<StatementNode>|null  $elseBody
     * @param  list<RedirectionNode>  $redirections
     */
    public function __construct(
        public array $clauses,
        public ?array $elseBody = null,
        public array $redirections = [],
        public ?int $line = null,
    ) {}

    public function getType(): string
    {
        return 'If';
    }
}
