<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Printenv extends AbstractCommand
{
    public function getName(): string
    {
        return 'printenv';
    }

    public function execute(array $args, CommandContext $ctx): ExecResult
    {
        if ($args === []) {
            // Print all environment variables
            $output = '';

            foreach ($ctx->env as $key => $value) {
                $output .= sprintf('%s=%s%s', $key, $value, PHP_EOL);
            }

            return $this->success($output);
        }

        // Print specific variable(s)
        $output = '';
        $found = false;

        foreach ($args as $name) {
            if (array_key_exists($name, $ctx->env)) {
                $output .= $ctx->env[$name]."\n";
                $found = true;
            }
        }

        if (! $found) {
            return $this->failure('', exitCode: 1);
        }

        return $this->success($output);
    }
}
