<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Sed_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'sed';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $inPlace = false;
        $expressions = [];
        $files = [];
        $i = 0;

        // Parse arguments
        while ($i < count($args)) {
            $arg = $args[$i];

            if ($arg === '-i') {
                $inPlace = true;
                $i++;
            } elseif ($arg === '-e') {
                $i++;

                if ($i < count($args)) {
                    $expressions[] = $args[$i];
                }

                $i++;
            } elseif (str_starts_with($arg, '-') && $arg !== '-') {
                // Skip unknown flags
                $i++;
            } else {
                // First non-flag argument is an expression if we have none yet
                if ($expressions === []) {
                    $expressions[] = $arg;
                } else {
                    $files[] = $arg;
                }

                $i++;
            }
        }

        if ($expressions === []) {
            return $this->failure("sed: no expression provided\n");
        }

        // Parse all substitute expressions
        $commands = [];

        foreach ($expressions as $expression) {
            $parsed = $this->parseSubstitution($expression);

            if ($parsed === null) {
                return $this->failure(sprintf('sed: invalid expression: %s%s', $expression, PHP_EOL));
            }

            $commands[] = $parsed;
        }

        // Read input
        if ($files !== []) {
            $output = '';

            foreach ($files as $file) {
                $path = $this->resolvePath($commandContext, $file);

                try {
                    $content = $commandContext->fs->readFile($path);
                } catch (RuntimeException) {
                    return $this->failure("sed: can't read {$file}: No such file or directory\n");
                }

                $processed = $this->applyCommands($content, $commands);

                if ($inPlace) {
                    $commandContext->fs->writeFile($path, $processed);
                } else {
                    $output .= $processed;
                }
            }

            if ($inPlace) {
                return $this->success();
            }

            return $this->success($output);
        }

        // Read from stdin
        $input = $commandContext->stdin;
        $processed = $this->applyCommands($input, $commands);

        return $this->success($processed);
    }

    /**
     * @param  list<array{pattern: string, replacement: string, global: bool, caseInsensitive: bool}>  $commands
     */
    private function applyCommands(string $input, array $commands): string
    {
        $lines = explode("\n", $input);
        $lastIndex = count($lines) - 1;
        $output = '';

        foreach ($lines as $i => $line) {
            $result = $line;

            foreach ($commands as $command) {
                $flags = $command['caseInsensitive'] ? 'i' : '';
                $delimiter = chr(1); // Use SOH as delimiter for preg
                $regex = $delimiter.$command['pattern'].$delimiter.($command['global'] ? '' : '').$flags;

                if ($command['global']) {
                    $result = preg_replace($regex, $command['replacement'], $result) ?? $result;
                } else {
                    $result = preg_replace($regex, $command['replacement'], $result, 1) ?? $result;
                }
            }

            $output .= $result;

            if ($i < $lastIndex) {
                $output .= "\n";
            }
        }

        return $output;
    }

    /**
     * @return array{pattern: string, replacement: string, global: bool, caseInsensitive: bool}|null
     */
    private function parseSubstitution(string $expr): ?array
    {
        if (! str_starts_with($expr, 's')) {
            return null;
        }

        if (strlen($expr) < 2) {
            return null;
        }

        $delimiter = $expr[1];
        $parts = [];
        $current = '';
        $escaped = false;

        for ($i = 2; $i < strlen($expr); $i++) {
            $ch = $expr[$i];

            if ($escaped) {
                $current .= '\\'.$ch;
                $escaped = false;

                continue;
            }

            if ($ch === '\\') {
                $escaped = true;

                continue;
            }

            if ($ch === $delimiter) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $ch;
        }

        // The remaining part (flags) is in $current
        $flagStr = $current;

        if (count($parts) < 2) {
            return null;
        }

        $pattern = $parts[0];
        $replacement = $parts[1];

        // Convert sed replacement syntax to PHP preg_replace syntax
        // \1, \2 etc. -> $1, $2
        $replacement = preg_replace('/\\\\(\d)/', '$$1', $replacement) ?? $replacement;
        // & -> $0
        $replacement = str_replace('&', '$0', $replacement);

        $global = str_contains($flagStr, 'g');
        $caseInsensitive = str_contains($flagStr, 'i');

        return [
            'pattern' => $pattern,
            'replacement' => $replacement,
            'global' => $global,
            'caseInsensitive' => $caseInsensitive,
        ];
    }
}
