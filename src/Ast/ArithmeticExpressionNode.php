<?php

declare(strict_types=1);

namespace BashBox\Ast;

/** Arithmetic source text, kept as written: it's expanded and evaluated when run, as in bash. */
final readonly class ArithmeticExpressionNode implements Node
{
    public function __construct(
        public string $originalText,
    ) {}
}
