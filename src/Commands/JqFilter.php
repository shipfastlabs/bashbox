<?php

declare(strict_types=1);

namespace BashBox\Commands;

/** Internal syntax tree for the deliberately small jq grammar. */
final readonly class JqFilter
{
    /**
     * @param  list<JqFilter>  $children
     * @param  list<string>  $keys
     */
    public function __construct(
        public string $kind,
        public mixed $value = null,
        public array $children = [],
        public array $keys = [],
    ) {}
}
