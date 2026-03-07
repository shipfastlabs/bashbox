<?php

declare(strict_types=1);

namespace BashBox\Ast;

final class WordNode implements Node
{
    /**
     * @param  list<WordPart>  $parts
     */
    public function __construct(
        public array $parts = [],
    ) {}

    public function getType(): string
    {
        return 'Word';
    }
}
