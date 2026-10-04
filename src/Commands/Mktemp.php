<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Mktemp extends AbstractCommand
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public function getName(): string
    {
        return 'mktemp';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $directory = false;
        $dryRun = false;
        $quiet = false;
        $useTmpdir = false;
        $tmpdir = null;
        $suffix = '';
        $template = null;
        $counter = count($args);

        for ($i = 0; $i < $counter; $i++) {
            $arg = $args[$i];

            if ($arg === '-p' || $arg === '--tmpdir') {
                $tmpdir = $args[++$i] ?? '';
            } elseif (str_starts_with($arg, '--tmpdir=')) {
                $tmpdir = substr($arg, 9);
            } elseif (str_starts_with($arg, '--suffix=')) {
                $suffix = substr($arg, 9);
            } elseif ($arg === '--directory') {
                $directory = true;
            } elseif ($arg === '--dry-run') {
                $dryRun = true;
            } elseif ($arg === '--quiet') {
                $quiet = true;
            } elseif (preg_match('/^-[dqut]+$/', $arg) === 1) {
                $directory = $directory || str_contains($arg, 'd');
                $quiet = $quiet || str_contains($arg, 'q');
                $dryRun = $dryRun || str_contains($arg, 'u');
                $useTmpdir = $useTmpdir || str_contains($arg, 't');
            } else {
                $template = $arg;
            }
        }

        // -q silences creation failures only; template errors are always reported, as in GNU
        $fail = fn (string $message): ExecResult => $this->failure($quiet ? '' : sprintf("mktemp: %s\n", $message));

        if ($useTmpdir && $template !== null && str_contains($template, '/')) {
            return $this->failure("mktemp: invalid template, '{$template}', contains directory separator\n");
        }

        $tmpBase = ($commandContext->env['TMPDIR'] ?? '') !== '' ? $commandContext->env['TMPDIR'] : '/tmp';

        // No template or -p/-t: the name goes in a temp dir; an explicit template is relative to the cwd
        $base = match (true) {
            $template === null, $tmpdir !== null => ($tmpdir ?? '') !== '' ? $tmpdir : $tmpBase,
            $useTmpdir => $tmpBase,
            default => null,
        };
        $template ??= 'tmp.XXXXXXXXXX';

        if (preg_match('/X{3,}$/', $template, $m) !== 1) {
            return $this->failure("mktemp: too few X's in template '{$template}'\n");
        }

        $prefix = substr($template, 0, -strlen($m[0]));
        $fs = $commandContext->fs;

        $failure = sprintf("failed to create %s via template '%s%s%s': %%s", $directory ? 'directory' : 'file', $base !== null ? rtrim($base, '/').'/' : '', $template, $suffix);

        // createExclusive never replaces an entry, so losing a race for a name just means another try
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $random = '';

            for ($j = 0; $j < strlen($m[0]); $j++) {
                $random .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $name = $prefix.$random.$suffix;
            $display = $base !== null ? rtrim($base, '/').'/'.$name : $name;
            $path = $this->resolvePath($commandContext, $display);

            if ($dryRun && $fs->exists($path)) {
                continue;
            }

            try {
                if (! $dryRun) {
                    $fs->createExclusive($path, $directory);
                }
            } catch (RuntimeException $runtimeException) {
                if (str_starts_with($runtimeException->getMessage(), 'EEXIST')) {
                    continue;
                }

                return $fail(sprintf($failure, $this->describeError($runtimeException)));
            }

            return $this->success($display."\n");
        }

        return $fail(sprintf($failure, 'File exists'));
    }
}
