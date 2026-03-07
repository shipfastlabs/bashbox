<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Grep_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'grep';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->parseFlags($args, [
            'i' => false,
            'v' => false,
            'c' => false,
            'n' => false,
            'l' => false,
            'r' => false,
            'E' => false,
            'F' => false,
            'w' => false,
            'q' => false,
            'e' => '',
        ]);

        $flags = $parsed['flags'];
        $remaining = $parsed['args'];

        /** @var string $pattern */
        $pattern = '';

        if ($flags['e'] !== '' && $flags['e'] !== false) {
            $pattern = (string) $flags['e'];
        } elseif ($remaining !== []) {
            $pattern = array_shift($remaining);
        } else {
            return $this->failure("grep: no pattern specified\n");
        }

        $files = $remaining;
        $readingStdin = $files === [];

        if ($readingStdin && ! $flags['r']) {
            return $this->grepContent($commandContext->stdin, '-', $pattern, $flags, false);
        }

        if ($flags['r'] && $files === []) {
            $files = ['.'];
        }

        $allFiles = [];

        foreach ($files as $file) {
            $path = $this->resolvePath($commandContext, $file);

            if ($flags['r']) {
                $this->collectFiles($commandContext, $path, $allFiles);
            } else {
                $allFiles[] = ['path' => $path, 'label' => $file];
            }
        }

        $multiFile = count($allFiles) > 1;
        $output = '';
        $matchFound = false;

        foreach ($allFiles as $allFile) {
            try {
                $content = $commandContext->fs->readFile($allFile['path']);
            } catch (RuntimeException) {
                if (! $flags['q']) {
                    $output .= "grep: {$allFile['label']}: No such file or directory\n";
                }

                continue;
            }

            $result = $this->grepContent($content, $allFile['label'], $pattern, $flags, $multiFile);

            if ($result->exitCode === 0) {
                $matchFound = true;
            }

            $output .= $result->stdout;

            if ($flags['q'] && $matchFound) {
                return $this->success();
            }
        }

        if ($flags['q']) {
            return new ExecResult(stdout: '', stderr: '', exitCode: $matchFound ? 0 : 1);
        }

        return new ExecResult(stdout: $output, stderr: '', exitCode: $matchFound ? 0 : 1);
    }

    /**
     * @param  array<string, string|bool>  $flags
     */
    private function grepContent(string $content, string $label, string $pattern, array $flags, bool $multiFile): ExecResult
    {
        ['lines' => $lines] = $this->splitLines($content);

        $regex = $this->buildRegex($pattern, $flags);
        $matchedLines = [];
        $matchCount = 0;
        $earlyExit = $flags['q'] || $flags['l'];

        foreach ($lines as $idx => $line) {
            $matches = (bool) @preg_match($regex, $line);

            if ($flags['v']) {
                $matches = ! $matches;
            }

            if ($matches) {
                $matchCount++;

                if ($earlyExit) {
                    break;
                }

                $matchedLines[] = ['num' => $idx + 1, 'text' => $line];
            }
        }

        if ($flags['q']) {
            return new ExecResult(stdout: '', stderr: '', exitCode: $matchCount > 0 ? 0 : 1);
        }

        if ($flags['l']) {
            if ($matchCount > 0) {
                return $this->success($label."\n");
            }

            return new ExecResult(stdout: '', stderr: '', exitCode: 1);
        }

        if ($flags['c']) {
            $prefix = $multiFile ? $label.':' : '';

            return new ExecResult(
                stdout: $prefix.$matchCount."\n",
                stderr: '',
                exitCode: $matchCount > 0 ? 0 : 1,
            );
        }

        $output = '';

        foreach ($matchedLines as $matchedLine) {
            $parts = [];

            if ($multiFile) {
                $parts[] = $label;
            }

            if ($flags['n']) {
                $parts[] = (string) $matchedLine['num'];
            }

            if ($parts !== []) {
                $output .= implode(':', $parts).':'.$matchedLine['text']."\n";
            } else {
                $output .= $matchedLine['text']."\n";
            }
        }

        return new ExecResult(stdout: $output, stderr: '', exitCode: $matchCount > 0 ? 0 : 1);
    }

    /**
     * @param  array<string, string|bool>  $flags
     */
    private function buildRegex(string $pattern, array $flags): string
    {
        $regex = $flags['F'] ? preg_quote($pattern, '/') : $pattern;

        if ($flags['w']) {
            $regex = '\b'.$regex.'\b';
        }

        $modifiers = '';

        if ($flags['i']) {
            $modifiers .= 'i';
        }

        return '/'.$regex.'/'.$modifiers;
    }

    /**
     * @param  list<array{path: string, label: string}>  $result
     */
    private function collectFiles(CommandContext $commandContext, string $dirPath, array &$result): void
    {
        try {
            $entries = $commandContext->fs->readdirWithFileTypes($dirPath);
        } catch (RuntimeException) {
            // If it's a file, not a directory
            try {
                $stat = $commandContext->fs->stat($dirPath);

                if ($stat->isFile) {
                    $result[] = ['path' => $dirPath, 'label' => $dirPath];
                }
            } catch (RuntimeException) {
                // ignore
            }

            return;
        }

        foreach ($entries as $entry) {
            $childPath = $dirPath.'/'.$entry->name;

            if ($entry->isFile) {
                $result[] = ['path' => $childPath, 'label' => $childPath];
            } elseif ($entry->isDirectory) {
                $this->collectFiles($commandContext, $childPath, $result);
            }
        }
    }
}
