<?php

declare(strict_types=1);

namespace BashBox\Exceptions;

final class ExitException extends BashException
{
    public function __construct(
        public readonly int $exitCode = 0,
    ) {
        parent::__construct('exit '.$exitCode, $exitCode);
    }
}
