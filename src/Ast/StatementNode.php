<?php

declare(strict_types=1);

namespace BashBox\Ast;

final readonly class StatementNode implements Node
{
    /**
     * @param  list<PipelineNode>  $pipelines
     * @param  list<string>  $operators  "&&" | "||"
     * @param  int  $line  where the statement starts
     * @param  int  $endLine  where it ends: what follows on that line belongs to the same input line
     */
    public function __construct(
        public array $pipelines,
        public array $operators,
        public bool $background,
        public int $line,
        public int $endLine,
    ) {}
}
