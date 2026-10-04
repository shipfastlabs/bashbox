<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Base64_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'base64';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->getopt($args, 'diw:', ['decode' => ['d', false], 'ignore-garbage' => ['i', false], 'wrap' => ['w', true]]);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $operands] = $parsed;
        $wrap = $flags['w'] ?? '76';

        if (! ctype_digit($wrap)) {
            return $this->failure(sprintf("base64: invalid wrap size: '%s'\n", $wrap));
        }

        [$contents, $stderr] = $this->readFiles($commandContext, $operands, "base64: %s: %s\n");

        if ($stderr !== '') {
            return $this->failure($stderr);
        }

        $input = implode('', $contents);

        if (isset($flags['d'])) {
            $decoded = base64_decode(isset($flags['i']) ? (string) preg_replace('/[^A-Za-z0-9+\/=]/', '', $input) : $input, true);

            return $decoded === false ? $this->failure("base64: invalid input\n") : $this->success($decoded);
        }

        $encoded = base64_encode($input);

        // -w 0 turns off wrapping, and the final newline with it.
        return $this->success((int) $wrap === 0 || $encoded === '' ? $encoded : implode("\n", str_split($encoded, max(1, (int) $wrap)))."\n");
    }
}
