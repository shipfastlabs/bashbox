<?php

declare(strict_types=1);

namespace BashBox\Ast\ParameterOps;

final readonly class LengthSliceErrorOp implements ParameterOperation
{
    public function getType(): string
    {
        return 'LengthSliceError';
    }
}
