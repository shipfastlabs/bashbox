<?php

declare(strict_types=1);

namespace BashBox\Commands;

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
     * @return list<string>
     */
    public function getNames(): array
    {
        return array_keys($this->commands);
    }

    public function registerDefaults(): void
    {
        $defaults = [
            new Echo_,
            new Printf_,
            new Cat,
            new Head,
            new Tail,
            new Tee,
            new Ls,
            new Pwd,
            new Mkdir_,
            new Rm,
            new Cp,
            new Mv,
            new Touch,
            new Grep_,
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
            new Rev,
            new Date_,
            new Which_,
            new Whoami_,
            new Hostname_,
            new Tree_,
            new Base64_,
            new Sed_,
        ];

        foreach ($defaults as $command) {
            $this->register($command);
        }
    }
}
