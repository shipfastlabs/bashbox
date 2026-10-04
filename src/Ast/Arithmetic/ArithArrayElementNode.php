<?php

declare(strict_types=1);

namespace BashBox\Ast\Arithmetic;

/** `name[subscript]`: the subscript stays text, since an associative array uses it as a key, unevaluated. */
final readonly class ArithArrayElementNode implements ArithExpr
{
    public function __construct(
        public string $name,
        public string $subscript,
    ) {}
}
