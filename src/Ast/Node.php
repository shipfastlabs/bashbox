<?php

declare(strict_types=1);

namespace BashBox\Ast;

interface Node
{
    public function getType(): string;
}
