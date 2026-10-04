<?php

declare(strict_types=1);

namespace BashBox\Exceptions;

/** An assignment the shell refuses: builtins and `((...))` fail with status 1, a plain assignment abandons its line. */
final class AssignmentException extends BashException
{
    public static function readonly(string $name): self
    {
        return new self(sprintf('bash: %s: readonly variable', $name));
    }
}
