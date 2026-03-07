<?php

declare(strict_types=1);

namespace BashBox\Exceptions;

final class UnboundVariableException extends BashException
{
    public function __construct(public readonly string $variable)
    {
        parent::__construct(sprintf('bash: %s: unbound variable', $variable));
    }
}
