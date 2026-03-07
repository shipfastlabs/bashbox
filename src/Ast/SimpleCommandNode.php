<?php

declare(strict_types=1);

namespace BashBox\Ast;

final class SimpleCommandNode implements Node
{
    /**
     * @param  list<AssignmentNode>  $assignments
     * @param  list<WordNode>  $args
     * @param  list<RedirectionNode>  $redirections
     */
    public function __construct(
        public ?WordNode $name = null,
        public array $args = [],
        public array $assignments = [],
        public array $redirections = [],
        public ?int $line = null,
    ) {}

    public function getType(): string
    {
        return 'SimpleCommand';
    }
}
