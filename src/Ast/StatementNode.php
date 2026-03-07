<?php

declare(strict_types=1);

namespace BashBox\Ast;

final class StatementNode implements Node
{
    /**
     * @param  list<PipelineNode>  $pipelines
     * @param  list<string>  $operators  "&&" | "||" | ";"
     * @param  array{message: string, token: string}|null  $deferredError
     */
    public function __construct(
        public array $pipelines = [],
        public array $operators = [],
        public bool $background = false,
        public ?array $deferredError = null,
        public ?string $sourceText = null,
        public ?int $line = null,
    ) {}

    public function getType(): string
    {
        return 'Statement';
    }
}
