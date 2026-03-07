<?php

declare(strict_types=1);

namespace BashBox\Ast;

final class PipelineNode implements Node
{
    /**
     * @param  list<SimpleCommandNode|CompoundCommandNode|FunctionDefNode>  $commands
     * @param  list<bool>|null  $pipeStderr
     */
    public function __construct(
        public array $commands = [],
        public bool $negated = false,
        public bool $timed = false,
        public bool $timePosix = false,
        public ?array $pipeStderr = null,
    ) {}

    public function getType(): string
    {
        return 'Pipeline';
    }
}
