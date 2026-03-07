<?php

declare(strict_types=1);

namespace BashBox\Ast\ParameterOps;

final readonly class IndirectionOp implements ParameterOperation
{
    public function __construct(
        public ?ParameterOperation $innerOp = null,
    ) {}

    public function getType(): string
    {
        return 'Indirection';
    }
}
