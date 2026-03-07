<?php

declare(strict_types=1);

namespace BashBox\Ast\Parts;

use BashBox\Ast\ParameterOps\ParameterOperation;
use BashBox\Ast\WordPart;

final class ParameterExpansionPart implements WordPart
{
    public function __construct(
        public string $parameter,
        public ?ParameterOperation $operation = null,
    ) {}

    public function getType(): string
    {
        return 'ParameterExpansion';
    }
}
