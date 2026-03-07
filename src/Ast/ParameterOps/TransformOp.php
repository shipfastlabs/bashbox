<?php

declare(strict_types=1);

namespace BashBox\Ast\ParameterOps;

final readonly class TransformOp implements ParameterOperation
{
    public function __construct(
        public string $operator,
    ) {}

    public function getType(): string
    {
        return 'Transform';
    }
}
