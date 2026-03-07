<?php

declare(strict_types=1);

namespace BashBox\Ast;

final class ScriptNode implements Node
{
    /**
     * @param  list<StatementNode>  $statements
     */
    public function __construct(
        public array $statements = [],
    ) {}

    public function getType(): string
    {
        return 'Script';
    }
}
