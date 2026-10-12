<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\Filesystem\FileSystemInterface;
use RuntimeException;

final class CommandRegistry
{
    /** @var array<string, CommandInterface> */
    private array $commands = [];

    public function register(CommandInterface $command): void
    {
        $this->commands[$command->getName()] = $command;
    }

    public function get(string $name): ?CommandInterface
    {
        return $this->commands[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /**
     * Bundled tools remain available independently of PATH; other names resolve to virtual scripts.
     * When executing, remember a non-executable file only if no later PATH entry can run.
     *
     * @param  array<string, string>  $env
     */
    public function resolve(string $name, FileSystemInterface $fileSystem, string $cwd, array $env, bool $executableOnly = true): ?string
    {
        if (! str_contains($name, '/') && $this->has($name)) {
            return '/usr/bin/'.$name;
        }

        $paths = str_contains($name, '/') ? [$name] : array_map(
            fn (string $directory): string => ($directory === '' ? '.' : rtrim($directory, '/')).'/'.$name,
            explode(':', $env['PATH'] ?? '/usr/local/bin:/usr/bin:/bin'),
        );
        $blocked = null;

        foreach ($paths as $path) {
            try {
                $stat = $fileSystem->stat($fileSystem->resolvePath($cwd, $path));
            } catch (RuntimeException) {
                continue;
            }

            if ($stat->isDirectory) {
                continue;
            }

            if (($stat->mode & 0o111) !== 0) {
                return $path;
            }

            $blocked ??= $path;
        }

        return $executableOnly ? null : $blocked;
    }

    public function registerDefaults(): void
    {
        $defaults = [
            new Echo_,
            new Printf_,
            new Shell_('bash'),
            new Shell_('sh'),
            new Cat,
            new Head,
            new Tail,
            new Tee,
            new Ls,
            new Pwd,
            new Mkdir_,
            new Rm,
            new Rmdir,
            new Cp,
            new Mv,
            new Touch,
            new Grep_,
            new Rg_,
            new Jq_,
            new Diff_,
            new Sort_,
            new Uniq_,
            new Wc,
            new Cut,
            new Tr,
            new Find_,
            new Xargs,
            new Env_,
            new Printenv,
            new Basename_,
            new Dirname_,
            new Seq,
            new True_,
            new False_,
            new Test_,
            new Test_('['),
            new Rev,
            new Date_,
            new Which_,
            new Whoami_,
            new Hostname_,
            new Tree_,
            new Base64_,
            new Sed_,
            new Yes,
            new Realpath,
            new Mktemp,
            new Ln,
            new Chmod,
            new Sleep_,
            new Checksum('md5'),
            new Checksum('sha1'),
            new Checksum('sha256'),
            new Stat_,
            new Du,
            new Od,
            new Tac,
            new Nl,
            new Paste,
            new Comm,
            new Fold,
            new Expand,
            new Unexpand,
        ];

        foreach ($defaults as $default) {
            $this->register($default);
        }
    }
}
