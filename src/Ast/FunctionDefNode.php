<?php

declare(strict_types=1);

namespace BashBox\Ast;

final readonly class FunctionDefNode implements CommandNode
{
    public function __construct(
        public string $name,
        public CompoundCommandNode $body,
        public int $line = 0,
    ) {}
}
