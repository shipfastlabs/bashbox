<?php

declare(strict_types=1);

namespace BashBox\Ast;

final class CaseNode implements CompoundCommandNode
{
    /**
     * @param  list<CaseItemNode>  $items
     * @param  list<RedirectionNode>  $redirections
     */
    public function __construct(
        public WordNode $word,
        public array $items = [],
        public array $redirections = [],
        public ?int $line = null,
    ) {}

    public function getType(): string
    {
        return 'Case';
    }
}
