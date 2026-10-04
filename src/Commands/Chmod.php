<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use BashBox\Filesystem\UnixFileMode;
use RuntimeException;

/** GNU `chmod [-cfvR] MODE FILE...`; symlinks on the command line are followed, those met while recursing are skipped. */
final class Chmod extends AbstractCommand
{
    public function getName(): string
    {
        return 'chmod';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $options = ['c' => false, 'f' => false, 'v' => false, 'R' => false];
        $operands = [];

        foreach ($args as $i => $arg) {
            if ($arg === '--') {
                array_push($operands, ...array_slice($args, $i + 1));

                break;
            }

            // `-w`, `-x`, `-rwx` are modes, not options
            if (preg_match('/^-[cfvR]+$/', $arg) === 1) {
                foreach (str_split(substr($arg, 1)) as $letter) {
                    $options[$letter] = true;
                }
            } elseif (preg_match('/^-[^rwxXstugoa0-7=+,-]/', $arg) === 1) {
                return $this->failure(sprintf("chmod: invalid option -- '%s'\nTry 'chmod --help' for more information.\n", $arg[1]));
            } else {
                $operands[] = $arg;
            }
        }

        if (count($operands) < 2) {
            return $this->failure(($operands === [] ? 'chmod: missing operand' : sprintf("chmod: missing operand after '%s'", $operands[0]))
                ."\nTry 'chmod --help' for more information.\n");
        }

        $spec = array_shift($operands);

        if (UnixFileMode::adjust($spec, 0) === null) {
            return $this->failure("chmod: invalid mode: '{$spec}'\nTry 'chmod --help' for more information.\n");
        }

        $output = '';
        $stderr = '';
        $failed = false;

        foreach ($operands as $operand) {
            $this->change($commandContext, $spec, $options, $operand, $this->resolvePath($commandContext, $operand), true, $output, $stderr, $failed);
        }

        // -f only silences the messages; the exit status still reports the failure
        return $failed ? $this->failure($stderr, 1, $output) : $this->success($output);
    }

    /** @param array<string, bool> $options */
    private function change(CommandContext $commandContext, string $spec, array $options, string $display, string $path, bool $operand, string &$output, string &$stderr, bool &$failed): void
    {
        $fs = $commandContext->fs;

        try {
            $lstat = $fs->lstat($path);
        } catch (RuntimeException) {
            $stderr .= $options['f'] ? '' : "chmod: cannot access '{$display}': No such file or directory\n";
            $failed = true;

            return;
        }

        if ($lstat->isSymbolicLink && ! $operand) {
            return;
        }

        try {
            $stat = $fs->stat($path);
        } catch (RuntimeException) {
            $stderr .= $options['f'] ? '' : "chmod: cannot operate on dangling symlink '{$display}'\n";
            $failed = true;

            return;
        }

        $old = $stat->mode & 07777;
        $new = (int) UnixFileMode::adjust($spec, $old, $stat->isDirectory, $commandContext->umask);

        try {
            $fs->chmod($path, $new);
        } catch (RuntimeException $runtimeException) {
            $stderr .= sprintf("chmod: changing permissions of '%s': %s\n", $display, $this->describeError($runtimeException));
            $failed = true;

            return;
        }

        if ($options['v'] || ($options['c'] && $new !== $old)) {
            $output .= $new === $old
                ? sprintf("mode of '%s' retained as %04o (%s)\n", $display, $new, UnixFileMode::symbolic($new))
                : sprintf("mode of '%s' changed from %04o (%s) to %04o (%s)\n", $display, $old, UnixFileMode::symbolic($old), $new, UnixFileMode::symbolic($new));
        }

        if ($options['R'] && $stat->isDirectory) {
            foreach ($fs->readdir($path) as $name) {
                $this->change($commandContext, $spec, $options, rtrim($display, '/').'/'.$name, rtrim($path, '/').'/'.$name, false, $output, $stderr, $failed);
            }
        }
    }
}
