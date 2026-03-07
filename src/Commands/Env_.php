<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Env_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'env';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $output = '';

        foreach ($commandContext->env as $key => $value) {
            $output .= sprintf('%s=%s%s', $key, $value, PHP_EOL);
        }

        return $this->success($output);
    }
}
