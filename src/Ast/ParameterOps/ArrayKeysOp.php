<?php

declare(strict_types=1);

namespace BashBox\Ast\ParameterOps;

final readonly class ArrayKeysOp implements ParameterOperation
{
    public function __construct(
        public string $array,
        public bool $star,
    ) {}

    public function getType(): string
    {
        return 'ArrayKeys';
    }
}
