<?php

declare(strict_types=1);

namespace BashBox\Ast;

final class HereDocNode implements Node
{
    public function __construct(
        public string $delimiter,
        public WordNode $content,
        public bool $stripTabs = false,
        public bool $quoted = false,
    ) {}

    public function getType(): string
    {
        return 'HereDoc';
    }
}
