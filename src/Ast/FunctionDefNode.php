<?php

declare(strict_types=1);

namespace BashBox\Ast;

final class FunctionDefNode implements Node
{
    /**
     * @param  list<RedirectionNode>  $redirections
     */
    public function __construct(
        public string $name,
        public CompoundCommandNode $body,
        public array $redirections = [],
        public ?string $sourceFile = null,
        public ?int $line = null,
    ) {}

    public function getType(): string
    {
        return 'FunctionDef';
    }
}
