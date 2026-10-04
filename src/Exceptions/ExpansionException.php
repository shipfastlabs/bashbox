<?php

declare(strict_types=1);

namespace BashBox\Exceptions;

/** An expansion error: fatal ones (`${x?}`, a bad `${x@op}`) end the script with 127 like bash, the rest fail only their line. */
final class ExpansionException extends BashException
{
    public function __construct(string $message, public readonly bool $fatal = false)
    {
        parent::__construct($message);
    }
}
