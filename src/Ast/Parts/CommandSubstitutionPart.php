<?php

declare(strict_types=1);

namespace BashBox\Ast\Parts;

use BashBox\Ast\ScriptNode;
use BashBox\Ast\WordPart;

final class CommandSubstitutionPart implements WordPart
{
    public function __construct(
        public ScriptNode $body,
        public bool $legacy = false,
    ) {}

    public function getType(): string
    {
        return 'CommandSubstitution';
    }
}
