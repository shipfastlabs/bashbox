<?php

declare(strict_types=1);

namespace BashBox\Ast;

/** A command a pipeline can run; $line is where it starts, for $LINENO. */
interface CommandNode extends Node
{
    public int $line { get; }
}
