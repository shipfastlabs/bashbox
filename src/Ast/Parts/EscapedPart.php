<?php

declare(strict_types=1);

namespace BashBox\Ast\Parts;

use BashBox\Ast\WordPart;

final readonly class EscapedPart implements WordPart
{
    public function __construct(
        public string $value,
    ) {}

    public function getType(): string
    {
        return 'Escaped';
    }
}
