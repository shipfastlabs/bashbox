<?php

declare(strict_types=1);

namespace BashBox\Ast;

final class AssignmentNode implements Node
{
    /**
     * @param  list<WordNode>|null  $array
     */
    public function __construct(
        public string $name,
        public ?WordNode $value = null,
        public bool $append = false,
        public ?array $array = null,
        public ?int $line = null,
    ) {}

    public function getType(): string
    {
        return 'Assignment';
    }
}
