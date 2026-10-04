<?php

declare(strict_types=1);

namespace BashBox\Ast;

final readonly class HereDocNode implements Node
{
    public function __construct(
        public string $delimiter,
        public WordNode $content,
        public bool $stripTabs = false,
        public bool $quoted = false,
    ) {}
}
