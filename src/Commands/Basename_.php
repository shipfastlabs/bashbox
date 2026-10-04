<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Basename_ extends AbstractCommand
{
    private const array LONG = [
        'multiple' => ['a', false],
        'suffix' => ['s', true],
        'zero' => ['z', false],
    ];

    public function getName(): string
    {
        return 'basename';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->getopt($args, '+as:z', self::LONG);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $names] = $parsed;
        $suffix = $flags['s'] ?? '';

        if ($names === []) {
            return $this->usageError('missing operand');
        }

        // Without -a or -s, a second operand is the suffix
        if (! isset($flags['a']) && ! isset($flags['s'])) {
            if (isset($names[2])) {
                return $this->usageError(sprintf("extra operand '%s'", $names[2]));
            }

            [$names, $suffix] = [[$names[0]], $names[1] ?? ''];
        }

        $end = isset($flags['z']) ? "\0" : "\n";

        return $this->success(implode('', array_map(fn (string $name): string => $this->base($name, $suffix).$end, $names)));
    }

    private function base(string $path, string $suffix): string
    {
        $trimmed = rtrim($path, '/');

        if ($trimmed === '') {
            return $path === '' ? '' : '/';
        }

        $base = substr($trimmed, (int) strrpos('/'.$trimmed, '/'));

        // The suffix is not removed when it is the whole name
        return $suffix !== '' && $base !== $suffix && str_ends_with($base, $suffix) ? substr($base, 0, -strlen($suffix)) : $base;
    }
}
