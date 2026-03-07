<?php

declare(strict_types=1);

namespace BashBox\Ast\ParameterOps;

use BashBox\Ast\WordNode;

final readonly class AssignDefaultOp implements ParameterOperation
{
    public function __construct(
        public WordNode $word,
        public bool $checkEmpty,
    ) {}

    public function getType(): string
    {
        return 'AssignDefault';
    }
}
