<?php

declare(strict_types=1);

namespace BashBox\Ast\ParameterOps;

use BashBox\Ast\WordNode;

final readonly class PatternReplacementOp implements ParameterOperation
{
    public function __construct(
        public WordNode $pattern,
        public ?WordNode $replacement = null,
        public bool $all = false,
        public ?string $anchor = null,
    ) {}

    public function getType(): string
    {
        return 'PatternReplacement';
    }
}
