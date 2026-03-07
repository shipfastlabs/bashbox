<?php

declare(strict_types=1);

namespace BashBox\Ast\ParameterOps;

final readonly class VarNamePrefixOp implements ParameterOperation
{
    public function __construct(
        public string $prefix,
        public bool $star,
    ) {}

    public function getType(): string
    {
        return 'VarNamePrefix';
    }
}
