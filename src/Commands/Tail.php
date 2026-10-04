<?php

declare(strict_types=1);

namespace BashBox\Commands;

use Override;

final class Tail extends Head
{
    #[Override]
    public function getName(): string
    {
        return 'tail';
    }

    #[Override]
    protected function select(array $units, string $count): array
    {
        // "+N" means "starting with the Nth"; otherwise the last N.
        $offset = str_starts_with($count, '+') ? (int) $count - 1 : count($units) - abs((int) $count);

        return array_slice($units, max(0, $offset));
    }
}
