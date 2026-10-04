<?php

declare(strict_types=1);

namespace BashBox\Ast;

interface CompoundCommandNode extends CommandNode
{
    /** @var list<RedirectionNode> */
    public array $redirections { get; }
}
