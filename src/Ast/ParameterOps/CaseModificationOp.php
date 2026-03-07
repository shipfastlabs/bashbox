<?php

declare(strict_types=1);

namespace BashBox\Ast\ParameterOps;

use BashBox\Ast\WordNode;

final readonly class CaseModificationOp implements ParameterOperation
{
    public function __construct(
        public string $direction,
        public bool $all,
        public ?WordNode $pattern = null,
    ) {}

    public function getType(): string
    {
        return 'CaseModification';
    }
}
