<?php

declare(strict_types=1);

namespace BashBox\Ast\ParameterOps;

final readonly class BadSubstitutionOp implements ParameterOperation
{
    public function __construct(
        public string $text,
    ) {}

    public function getType(): string
    {
        return 'BadSubstitution';
    }
}
