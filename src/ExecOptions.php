<?php

declare(strict_types=1);

namespace BashBox;

final readonly class ExecOptions
{
    /**
     * @param  array<string, string>  $env
     */
    public function __construct(
        public ?string $cwd = null,
        public array $env = [],
        public ?Limits $limits = null,
        public string $stdin = '',
    ) {}
}
