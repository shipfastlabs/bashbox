<?php

declare(strict_types=1);

namespace BashBox;

use BashBox\Filesystem\FileSystemInterface;
use BashBox\Network\NetworkConfig;

final readonly class BashOptions
{
    /**
     * @param  array<string, string>  $env
     * @param  array<string, string>  $initialFiles
     */
    public function __construct(
        public ?FileSystemInterface $fs = null,
        public string $cwd = '/home/user',
        public array $env = [],
        public Limits $limits = new Limits,
        public array $initialFiles = [],
        public ?NetworkConfig $network = null,
    ) {}
}
