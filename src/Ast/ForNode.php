<?php

declare(strict_types=1);

namespace BashBox\Ast;

final class ForNode implements CompoundCommandNode
{
    /**
     * @param  list<WordNode>|null  $words
     * @param  list<StatementNode>  $body
     * @param  list<RedirectionNode>  $redirections
     */
    public function __construct(
        public string $variable,
        public ?array $words,
        public array $body,
        public array $redirections = [],
        public ?int $line = null,
    ) {}

    public function getType(): string
    {
        return 'For';
    }
}
