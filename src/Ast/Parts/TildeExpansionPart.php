<?php

declare(strict_types=1);

namespace BashBox\Ast\Parts;

use BashBox\Ast\WordPart;

final readonly class TildeExpansionPart implements WordPart
{
    public function __construct(
        public ?string $user = null,
    ) {}

    public function getType(): string
    {
        return 'TildeExpansion';
    }
}
