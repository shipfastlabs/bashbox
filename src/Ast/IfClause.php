<?php

declare(strict_types=1);

namespace BashBox\Ast;

final readonly class IfClause
{
    /**
     * @param  list<StatementNode>  $condition
     * @param  list<StatementNode>  $body
     */
    public function __construct(
        public array $condition,
        public array $body,
    ) {}
}
