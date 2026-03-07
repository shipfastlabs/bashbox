<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

final class ArithAssignmentNode implements ArithExpr
{
    public function __construct(
        public string $operator,
        public string $variable,
        public ArithExpr $value,
        public ?ArithExpr $subscript = null,
        public ?string $stringKey = null,
    ) {}

    public function getType(): string
    {
        return 'ArithAssignment';
    }
}
