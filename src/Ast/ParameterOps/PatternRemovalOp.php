<?php

declare(strict_types=1);

namespace BashBox\Ast\ParameterOps;

use BashBox\Ast\WordNode;

final readonly class PatternRemovalOp implements ParameterOperation
{
    public function __construct(
        public WordNode $pattern,
        public string $side,
        public bool $greedy,
    ) {}

    public function getType(): string
    {
        return 'PatternRemoval';
    }
}
