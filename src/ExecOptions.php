<?php

declare(strict_types=1);

namespace BashBox;

use InvalidArgumentException;

final readonly class ExecOptions
{
    /** @var list<string> */
    public array $args;

    /**
     * @param  array<string, string>  $env
     * @param  array<array-key, mixed>  $args  A list of literal strings appended to the first executed command, not shell positional parameters.
     */
    public function __construct(
        public ?string $cwd = null,
        public array $env = [],
        public ?Limits $limits = null,
        public string $stdin = '',
        array $args = [],
    ) {
        $strings = array_filter($args, is_string(...));

        if (! array_is_list($strings) || $strings !== $args) {
            throw new InvalidArgumentException('ExecOptions args must be a list of strings');
        }

        $this->args = $strings;
    }
}
