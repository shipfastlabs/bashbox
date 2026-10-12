<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\Exceptions\ExecutionLimitException;
use BashBox\ExecResult;
use Closure;
use RuntimeException;

/** Non-interactive bash and sh both use the sandbox's bash interpreter. */
final class Shell_ extends AbstractCommand
{
    public function __construct(private readonly string $name) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $script = null;
        $stdinMode = false;

        while (isset($args[0]) && str_starts_with($args[0], '-') && $args[0] !== '-') {
            $option = array_shift($args);

            if ($option === '--') {
                break;
            }

            if ($option === '-c') {
                $script = array_shift($args);

                if ($script === null) {
                    return $this->usageError('-c requires a command string', 2);
                }

                break;
            }

            if ($option !== '-s') {
                return $this->usageError('unsupported option: '.$option, 2);
            }

            $stdinMode = true;
        }

        $name = $this->name;

        if ($script !== null) {
            $name = array_shift($args) ?? $name;
        } elseif (! $stdinMode && $args !== [] && $args[0] !== '-') {
            $name = array_shift($args);

            try {
                // Unlike invoking ./script, bash script does not require the execute bit.
                $script = $commandContext->fs->readFile($this->resolvePath($commandContext, $name));
            } catch (RuntimeException $runtimeException) {
                if ($runtimeException instanceof ExecutionLimitException) {
                    throw $runtimeException;
                }

                $error = $this->describeError($runtimeException);

                return $this->failure(sprintf("%s: %s: %s\n", $this->name, $name, $error), $error === 'No such file or directory' ? 127 : 126);
            }
        } else {
            if (($args[0] ?? null) === '-') {
                array_shift($args);
            }

            $script = $commandContext->stdin;
        }

        return $commandContext->shell instanceof Closure
            ? ($commandContext->shell)($script, $name, $args)
            : $this->failure($this->name.": child shell unavailable\n", 126);
    }
}
