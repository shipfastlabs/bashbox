<?php

declare(strict_types=1);

namespace BashBox\Interpreter;

use BashBox\Ast\CompoundCommandNode;
use BashBox\Limits;

final class InterpreterState
{
    /** @var array<string, array{body: CompoundCommandNode, sourceFile: ?string}> */
    public array $functions = [];

    /** @var list<array<string, string>> */
    public array $localScopes = [];

    public int $lastExitCode = 0;

    public int $commandCount = 0;

    public int $callDepth = 0;

    /** @var list<string> */
    public array $positionalParams = [];

    /** @var array<string, bool> */
    public array $shellOpts = [
        'errexit' => false,
        'nounset' => false,
        'pipefail' => false,
        'noclobber' => false,
        'noglob' => false,
        'xtrace' => false,
        'verbose' => false,
    ];

    /** @var array<string, array<int|string, string>> */
    public array $arrays = [];

    /** @var array<string, string> */
    public array $exportedVars = [];

    /** @var array<string, string> */
    public array $aliases = [];

    /**
     * @param  array<string, string>  $env
     */
    public function __construct(public array $env = [], public string $cwd = '/home/user', public readonly Limits $limits = new Limits) {}

    public function getVar(string $name): ?string
    {
        // Check local scopes first (most recent first)
        for ($i = count($this->localScopes) - 1; $i >= 0; $i--) {
            if (array_key_exists($name, $this->localScopes[$i])) {
                return $this->localScopes[$i][$name];
            }
        }

        return $this->env[$name] ?? null;
    }

    public function setVar(string $name, string $value): void
    {
        // Set in most recent local scope if one exists and var is already local
        for ($i = count($this->localScopes) - 1; $i >= 0; $i--) {
            if (array_key_exists($name, $this->localScopes[$i])) {
                $this->localScopes[$i][$name] = $value;

                return;
            }
        }

        $this->env[$name] = $value;
    }

    public function unsetVar(string $name): void
    {
        for ($i = count($this->localScopes) - 1; $i >= 0; $i--) {
            if (array_key_exists($name, $this->localScopes[$i])) {
                unset($this->localScopes[$i][$name]);

                return;
            }
        }

        unset($this->env[$name]);
    }

    public function pushLocalScope(): void
    {
        $this->localScopes[] = [];
    }

    public function popLocalScope(): void
    {
        array_pop($this->localScopes);
    }

    public function declareLocal(string $name, string $value): void
    {
        if ($this->localScopes === []) {
            $this->env[$name] = $value;

            return;
        }

        $this->localScopes[count($this->localScopes) - 1][$name] = $value;
    }

    public function incrementCommandCount(): void
    {
        $this->commandCount++;
        if ($this->commandCount > $this->limits->maxCommandCount) {
            throw new \BashBox\Exceptions\ExecutionLimitException(
                sprintf('Command count limit exceeded (%d)', $this->limits->maxCommandCount),
            );
        }
    }

    /**
     * @return array<string, string>
     */
    public function getExportedEnv(): array
    {
        return array_merge($this->env, $this->exportedVars);
    }

    public function getSpecialVar(string $name): ?string
    {
        return match ($name) {
            '?' => (string) $this->lastExitCode,
            '#' => (string) count($this->positionalParams),
            '0' => 'bashbox',
            '$' => '1',
            '!' => '',
            '-' => $this->getSetFlags(),
            '*' => implode(' ', $this->positionalParams),
            '@' => implode(' ', $this->positionalParams),
            '_' => '',
            'LINENO' => '0',
            'RANDOM' => (string) random_int(0, 32767),
            'SECONDS' => '0',
            'BASHPID' => '1',
            'BASH_VERSION' => '5.2.0(1)-release',
            'BASH_VERSINFO' => '5',
            'HOSTNAME' => $this->env['HOSTNAME'] ?? 'localhost',
            'PWD' => $this->cwd,
            'OLDPWD' => $this->env['OLDPWD'] ?? '',
            'HOME' => $this->env['HOME'] ?? '/home/user',
            'USER' => $this->env['USER'] ?? 'user',
            'IFS' => $this->env['IFS'] ?? " \t\n",
            'PATH' => $this->env['PATH'] ?? '/usr/local/bin:/usr/bin:/bin',
            default => null,
        };
    }

    private function getSetFlags(): string
    {
        $flags = '';
        if ($this->shellOpts['errexit'] ?? false) {
            $flags .= 'e';
        }

        if ($this->shellOpts['nounset'] ?? false) {
            $flags .= 'u';
        }

        if ($this->shellOpts['pipefail'] ?? false) {
            $flags .= '';
        }

        if ($this->shellOpts['xtrace'] ?? false) {
            $flags .= 'x';
        }

        return $flags.'hB';
    }
}
