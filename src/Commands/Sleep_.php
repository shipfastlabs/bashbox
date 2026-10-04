<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Sleep_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'sleep';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->getopt($args, '');

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [, $operands] = $parsed;

        if ($operands === []) {
            return $this->usageError('missing operand');
        }

        $stderr = '';

        foreach ($operands as $operand) {
            // strtod syntax (decimal, hex, infinity) plus a unit; only a zero may be negative
            $valid = preg_match('/^\s*([-+]?)(?:(\d+\.?\d*|\.\d+)(?:e[-+]?\d+)?|0x([\da-f]+\.?[\da-f]*|\.[\da-f]+)(?:p[-+]?\d+)?|inf(?:inity)?)[smhd]?$/i', $operand, $m) === 1
                && ($m[1] !== '-' || preg_match('/^0*\.?0*$/', ($m[2] ?? '').($m[3] ?? '')) === 1 && ($m[2] ?? '').($m[3] ?? '') !== '');
            $stderr .= $valid ? '' : sprintf("sleep: invalid time interval '%s'\n", $operand);
        }

        // ponytail: never actually sleeps, so scripts can't stall the sandbox
        return $stderr === '' ? $this->success() : $this->failure($stderr."Try 'sleep --help' for more information.\n");
    }
}
