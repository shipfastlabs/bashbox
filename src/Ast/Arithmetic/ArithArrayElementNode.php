<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

final class ArithArrayElementNode implements ArithExpr
{
    public function __construct(
        public string $array,
        public ?ArithExpr $index = null,
        public ?string $stringKey = null,
    ) {}

    public function getType(): string
    {
        return 'ArithArrayElement';
    }
}
