<?php

declare(strict_types=1);

namespace BashBox\Ast\ParameterOps;

interface ParameterOperation
{
    public function getType(): string;
}
