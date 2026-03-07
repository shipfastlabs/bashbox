<?php

declare(strict_types=1);

namespace BashBox\Ast;

final class RedirectionNode implements Node
{
    public function __construct(
        public string $operator,
        public WordNode|HereDocNode $target,
        public ?int $fd = null,
        public ?string $fdVariable = null,
    ) {}

    public function getType(): string
    {
        return 'Redirection';
    }
}
