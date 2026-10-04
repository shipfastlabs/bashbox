<?php

declare(strict_types=1);

namespace BashBox\Commands;

use Override;

final class Mv extends Cp
{
    #[Override]
    public function getName(): string
    {
        return 'mv';
    }
}
