<?php

declare(strict_types=1);

namespace BashBox\Ast\Parts;

use BashBox\Ast\WordPart;

final class BraceExpansionPart implements WordPart
{
    /**
     * @param  list<array{type: string, word?: \BashBox\Ast\WordNode, start?: string|int, end?: string|int, step?: int, startStr?: string, endStr?: string}>  $items
     */
    public function __construct(
        public array $items = [],
    ) {}

    public function getType(): string
    {
        return 'BraceExpansion';
    }
}
