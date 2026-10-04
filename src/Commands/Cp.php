<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

/** `cp`; `mv` shares everything but the final filesystem operation and a few messages. */
class Cp extends AbstractCommand
{
    public function getName(): string
    {
        return 'cp';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $name = $this->getName();
        $move = $name === 'mv';
        $fs = $commandContext->fs;

        // Overwriting is already the default, so -f is accepted and ignored.
        $parsed = $move
            ? $this->getopt($args, 'fn', ['force' => ['f', false], 'no-clobber' => ['n', false]])
            : $this->getopt($args, 'fnpRr', ['force' => ['f', false], 'no-clobber' => ['n', false], 'recursive' => ['r', false]]);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $sources] = $parsed;
        $recursive = $move || isset($flags['r']) || isset($flags['R']);

        if (count($sources) < 2) {
            return $this->usageError($sources === [] ? 'missing file operand' : sprintf("missing destination file operand after '%s'", $sources[0]));
        }

        $dest = array_pop($sources);
        $destPath = $this->resolvePath($commandContext, $dest);
        $destExists = $fs->exists($destPath);
        $destIsDir = $destExists && $fs->stat($destPath)->isDirectory;

        if (count($sources) > 1 && ! $destIsDir) {
            return $this->failure(sprintf("%s: target '%s': %s\n", $name, $dest, $destExists ? 'Not a directory' : 'No such file or directory'));
        }

        $stderr = '';

        foreach ($sources as $source) {
            $srcPath = $this->resolvePath($commandContext, $source);
            $target = $destIsDir ? rtrim($dest, '/').'/'.basename($source) : $dest;
            $targetPath = $this->resolvePath($commandContext, $target);

            $error = match (true) {
                ! $fs->exists($srcPath) => sprintf("cannot stat '%s': No such file or directory", $source),
                ! $recursive && $fs->stat($srcPath)->isDirectory => sprintf("-r not specified; omitting directory '%s'", $source),
                $srcPath === $targetPath => sprintf("'%s' and '%s' are the same file", $source, $target),
                $fs->stat($srcPath)->isDirectory && str_starts_with($targetPath, $srcPath.'/') => $move
                    ? sprintf("cannot move '%s' to a subdirectory of itself, '%s'", $source, $target)
                    : sprintf("cannot copy a directory, '%s', into itself, '%s'", $source, $target),
                // the filesystem API creates missing parents, so cp/mv check them here like the kernel would
                ! $fs->exists(dirname($targetPath)) => $move
                    ? sprintf("cannot move '%s' to '%s': No such file or directory", $source, $target)
                    : sprintf("cannot create %s '%s': No such file or directory", $fs->stat($srcPath)->isDirectory ? 'directory' : 'regular file', $target),
                ! $fs->stat(dirname($targetPath))->isDirectory => sprintf("cannot stat '%s': Not a directory", $target),
                default => null,
            };

            if ($error !== null) {
                $stderr .= sprintf("%s: %s\n", $name, $error);
            } elseif (isset($flags['n']) && $fs->exists($targetPath)) {
                continue;
            } else {
                try {
                    $move
                        ? $fs->mv($srcPath, $targetPath)
                        : $fs->cp($srcPath, $targetPath, ['recursive' => $recursive, 'preserve' => isset($flags['p'])]);
                } catch (RuntimeException $e) {
                    $stderr .= $name.': '.match (strstr($e->getMessage(), ':', true)) {
                        'ENOTEMPTY' => sprintf("cannot overwrite '%s': Directory not empty", $target),
                        'ENOSPC' => sprintf("error writing '%s': No space left on device", $target),
                        'EISDIR', 'ENOTDIR', 'EEXIST' => $fs->stat($srcPath)->isDirectory
                            ? sprintf("cannot overwrite non-directory '%s' with directory '%s'", $target, $source)
                            : sprintf("cannot overwrite directory '%s' with non-directory '%s'", $target, $source),
                        default => match (true) {
                            ! $move => sprintf("cannot create regular file '%s': %s", $target, $this->describeError($e)),
                            // /dev is a filesystem of its own on Linux, so these moves are copies an unprivileged user can't make
                            $srcPath === '/dev/null' && dirname($targetPath) !== '/dev' => sprintf("cannot create special file '%s': Operation not permitted", $target),
                            $targetPath === '/dev/null' && dirname($srcPath) !== '/dev' => sprintf("inter-device move failed: '%s' to '%s'; unable to remove target: Permission denied", $source, $target),
                            default => sprintf("cannot move '%s' to '%s': %s", $source, $target, $this->describeError($e)),
                        },
                    }."\n";
                }
            }
        }

        return $stderr === '' ? $this->success() : $this->failure($stderr);
    }
}
