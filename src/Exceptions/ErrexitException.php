<?php

declare(strict_types=1);

namespace BashBox\Exceptions;

final class ErrexitException extends BashException
{
    public function __construct(
        public readonly int $exitCode = 1,
    ) {
        parent::__construct('errexit: command failed with exit code '.$exitCode);
    }
}
