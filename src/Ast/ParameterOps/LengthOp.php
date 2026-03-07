<?php

declare(strict_types=1);

namespace BashBox\Ast\ParameterOps;

final readonly class LengthOp implements ParameterOperation
{
    public function getType(): string
    {
        return 'Length';
    }
}
