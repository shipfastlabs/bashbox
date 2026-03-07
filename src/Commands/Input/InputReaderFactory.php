<?php

declare(strict_types=1);

namespace BashBox\Commands\Input;

final class InputReaderFactory
{
    public static function create(bool $allowMultiple = true): InputReaderInterface
    {
        if (! $allowMultiple) {
            return new StdinInputReader;
        }

        return new MultiFileInputReader;
    }

    public static function createStdin(): InputReaderInterface
    {
        return new StdinInputReader;
    }
}
