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

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        if ($args === []) {
            return $this->failure("seq: missing operand\n");
        }

        // Parse flags
        $parsed = $this->parseFlags($args, [
            'f' => '%g',
            's' => "\n",
        ]);

        $flags = $parsed['flags'];
        $remaining = $parsed['args'];

        if ($remaining === []) {
            return $this->failure("seq: missing operand\n");
        }

        $format = (string) $flags['f'];
        $separator = (string) $flags['s'];

        $first = 1;
        $increment = 1;
        $last = 0;

        switch (count($remaining)) {
            case 1:
                $last = (int) $remaining[0];

                break;

            case 2:
                $first = (int) $remaining[0];
                $last = (int) $remaining[1];

                break;

            default:
                $first = (int) $remaining[0];
                $increment = (int) $remaining[1];
                $last = (int) $remaining[2];

                break;
        }

        if ($increment === 0) {
            return $this->failure("seq: zero increment\n");
        }

        $output = '';
        $numbers = [];

        if ($increment > 0) {
            for ($i = $first; $i <= $last; $i += $increment) {
                $numbers[] = $i;
            }
        } else {
            for ($i = $first; $i >= $last; $i += $increment) {
                $numbers[] = $i;
            }
        }

        // Format and join with separator
        $formattedNumbers = [];

        try {
            foreach ($numbers as $num) {
                $formattedNumbers[] = $this->formatNumber($num, $format);
            }
        } catch (\ValueError) {
            return $this->failure("seq: invalid format string: {$format}\n");
        }

        $output = implode($separator, $formattedNumbers);

        if ($output !== '') {
            $output .= "\n";
        }

        return $this->success($output);
    }

    /**
     * Format a number according to the format string.
     * Supports: %g (default), %e (scientific), %f (fixed), %d (integer)
     */
    private function formatNumber(int $number, string $format): string
    {
        return match ($format) {
            '%g', '%G', '%d', '%i' => (string) $number,
            '%e' => sprintf('%.6e', $number),
            '%E' => sprintf('%.6E', $number),
            '%f' => sprintf('%.6f', $number),
            '%F' => sprintf('%.6F', $number),
            default => sprintf($format, $number),
        };
    }
}
