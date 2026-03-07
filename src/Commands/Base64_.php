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
        $parsed = $this->parseFlags($args, [
            'd' => false,
            'D' => false,
            'decode' => false,
        ]);

        $decode = $parsed['flags']['d'] || $parsed['flags']['D'] || $parsed['flags']['decode'];
        $input = $commandContext->stdin;

        if ($decode) {
            $decoded = base64_decode($input, true);

            if ($decoded === false) {
                return $this->failure("base64: invalid input\n");
            }

            return $this->success($decoded);
        }

        $encoded = base64_encode($input);

        return $this->success($encoded."\n");
    }
}
