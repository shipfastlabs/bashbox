<?php

declare(strict_types=1);

namespace BashBox\Exceptions;

final class ContinueException extends BashException
{
    public function __construct(
        public readonly int $levels = 1,
    ) {
        parent::__construct('continue');
    }
}
