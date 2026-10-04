<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use InvalidArgumentException;

final class Env_ extends AbstractCommand
{
    private const array LONG = [
        'ignore-environment' => ['i', false],
        'unset' => ['u', true],
    ];

    public function getName(): string
    {
        return 'env';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        try {
            // Options end at the first operand, so the command keeps its own.
            [$options, $operands] = Getopt::parse($args, '+iu:', self::LONG);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->usageError($invalidArgumentException->getMessage(), 125);
        }

        $env = $commandContext->env;

        // A lone `-` is an obsolete -i.
        if (($operands[0] ?? '') === '-') {
            array_shift($operands);
            $env = [];
        }

        foreach ($options as [$option, $name]) {
            if ($option === 'i') {
                $env = [];
            } elseif ($name === '' || str_contains($name, '=')) {
                return $this->failure(sprintf("env: cannot unset '%s': Invalid argument\n", $name), 125);
            } else {
                unset($env[$name]);
            }
        }

        while (isset($operands[0]) && str_contains($operands[0], '=')) {
            [$name, $value] = explode('=', array_shift($operands), 2);

            if ($name === '') {
                return $this->failure(sprintf("env: cannot set '=%s': Invalid argument\n", $value), 125);
            }

            $env[$name] = $value;
        }

        if ($operands === []) {
            return $this->success(implode('', array_map(fn (string $name, string $value): string => $name.'='.$value."\n", array_keys($env), $env)));
        }

        $result = $this->spawn($commandContext, $operands, $env, $commandContext->stdin);

        return is_array($result) ? $this->failure(sprintf("env: '%s': %s\n", $operands[0], $result[0]), $result[1]) : $result;
    }
}
