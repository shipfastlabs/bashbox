<?php

declare(strict_types=1);

namespace BashBox\Ast;

final readonly class SimpleCommandNode implements CommandNode
{
    /**
     * @param  list<AssignmentNode>  $assignments
     * @param  list<WordNode>  $args
     * @param  list<RedirectionNode>  $redirections
     * @param  array<int, AssignmentNode>  $arrayArgs  `name=(...)` operands of declare/local/typeset/readonly/export,
     *                                                 keyed by their position in $args (which holds just the name)
     */
    public function __construct(
        public ?WordNode $name = null,
        public array $args = [],
        public array $assignments = [],
        public array $redirections = [],
        public int $line = 0,
        public array $arrayArgs = [],
    ) {}
}
