<?php

declare(strict_types=1);

namespace BashBox\Ast\Parts;

use BashBox\Ast\WordPart;

final readonly class GlobPart implements WordPart
{
    public function __construct(
        public string $pattern,
    ) {}

    public function getType(): string
    {
        return 'Glob';
    }
}
