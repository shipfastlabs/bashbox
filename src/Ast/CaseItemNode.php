<?php

declare(strict_types=1);

namespace BashBox\Ast;

final class CaseItemNode implements Node
{
    /**
     * @param  list<WordNode>  $patterns
     * @param  list<StatementNode>  $body
     */
    public function __construct(
        public array $patterns,
        public array $body = [],
        public string $terminator = ';;',
    ) {}

    public function getType(): string
    {
        return 'CaseItem';
    }
}
