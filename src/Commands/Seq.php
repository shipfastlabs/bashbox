<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Seq extends AbstractCommand
{
    public function getName(): string
    {
        return 'seq';
    }

    public function execute(array $args, CommandContext $ctx): ExecResult
    {
        if ($args === []) {
            return $this->failure("seq: missing operand\n");
        }

        $first = 1;
        $increment = 1;
        $last = 0;

        switch (count($args)) {
            case 1:
                $last = (int) $args[0];
                break;
            case 2:
                $first = (int) $args[0];
                $last = (int) $args[1];
                break;
            default:
                $first = (int) $args[0];
                $increment = (int) $args[1];
                $last = (int) $args[2];
                break;
        }

        if ($increment === 0) {
            return $this->failure("seq: zero increment\n");
        }

        $output = '';

        if ($increment > 0) {
            for ($i = $first; $i <= $last; $i += $increment) {
                $output .= $i."\n";
            }
        } else {
            for ($i = $first; $i >= $last; $i += $increment) {
                $output .= $i."\n";
            }
        }

        return $this->success($output);
    }
}
