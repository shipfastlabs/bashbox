<?php

declare(strict_types=1);

namespace BashBox\Ast\Parts;

use BashBox\Ast\WordPart;

final class DoubleQuotedPart implements WordPart
{
    /**
     * @param  list<WordPart>  $parts
     */
    public function __construct(
        public array $parts = [],
    ) {}

    public function getType(): string
    {
        return 'DoubleQuoted';
    }
}
