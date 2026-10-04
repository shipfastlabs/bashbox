<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use BashBox\Regex\PosixRegex;
use BashBox\Regex\RegexException;
use BashBox\Regex\SafePcreRegex;
use InvalidArgumentException;
use RuntimeException;

/** GNU grep with options -E -F -G -e -i -v -w -x -c -l -n -o -q -h -H -r. */
final class Grep_ extends AbstractCommand
{
    private const string USAGE = "Usage: grep [OPTION]... PATTERNS [FILE]...\nTry 'grep --help' for more information.\n";

    private const array LONG = [
        'extended-regexp' => ['E', false],
        'fixed-strings' => ['F', false],
        'basic-regexp' => ['G', false],
        'regexp' => ['e', true],
        'ignore-case' => ['i', false],
        'word-regexp' => ['w', false],
        'line-regexp' => ['x', false],
        'invert-match' => ['v', false],
        'count' => ['c', false],
        'files-with-matches' => ['l', false],
        'line-number' => ['n', false],
        'only-matching' => ['o', false],
        'quiet' => ['q', false],
        'silent' => ['q', false],
        'no-filename' => ['h', false],
        'with-filename' => ['H', false],
        'recursive' => ['r', false],
    ];

    public function getName(): string
    {
        return 'grep';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        try {
            [$options, $files] = Getopt::parse($args, 'EFGHce:hilnoqrvwx', self::LONG);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->failure(sprintf("grep: %s\n", $invalidArgumentException->getMessage()).self::USAGE, 2);
        }

        $flags = array_fill_keys(str_split('EFGicvnlrwxoqhH'), false);
        $matcher = null;
        $patterns = [];

        foreach ($options as [$option, $value]) {
            if ($option === 'e') {
                $patterns[] = $value;
            } elseif (str_contains('EFG', $option) && ($matcher ??= $option) !== $option) {
                return $this->failure("grep: conflicting matchers specified\n", 2);
            } else {
                $flags[$option] = true;
            }
        }

        if ($patterns === []) {
            if ($files === []) {
                return $this->failure(self::USAGE, 2);
            }

            $patterns[] = array_shift($files);
        }

        $regex = $this->buildRegex($patterns, $flags);
        $error = PosixRegex::error($regex);

        if ($error !== null) {
            return $this->failure(sprintf("grep: %s\n", $error), 2);
        }

        try {
            return $files === [] && ! $flags['r']
                ? $this->grepContent($commandContext->stdin, '(standard input)', $regex, $flags, $flags['H'])
                : $this->grepFiles($commandContext, $files, $regex, $flags);
        } catch (RegexException $regexException) {
            return $this->failure(sprintf("grep: %s\n", $regexException->getMessage()), 2);
        }
    }

    /**
     * @param  list<string>  $files
     * @param  array<string, bool>  $flags
     */
    private function grepFiles(CommandContext $commandContext, array $files, string $regex, array $flags): ExecResult
    {

        $stderr = '';
        $targets = [];

        // grep -r with no operand searches '.' but prints names without the './' prefix.
        $operands = $files === [] ? [['', '.']] : array_map(fn (string $file): array => [$file, $file], $files);

        foreach ($operands as [$label, $file]) {
            $path = $this->resolvePath($commandContext, $file);

            if ($flags['r']) {
                $this->collectFiles($commandContext, $path, $label, false, $targets, $stderr);
            } else {
                $targets[] = ['path' => $path, 'label' => $label, 'recursed' => false];
            }
        }

        $output = '';
        $matchFound = false;

        foreach ($targets as $target) {
            try {
                $content = $commandContext->fs->readFile($target['path']);
            } catch (RuntimeException $runtimeException) {
                $stderr .= sprintf("grep: %s: %s\n", $target['label'], $this->describeError($runtimeException));

                continue;
            }

            // File names are shown for several operands or files found by recursion, unless -h; -H forces them.
            $withName = $flags['H'] || (! $flags['h'] && (count($files) > 1 || $target['recursed']));
            $result = $this->grepContent($content, $target['label'], $regex, $flags, $withName);
            $output .= $result->stdout;

            if ($result->exitCode === 0) {
                $matchFound = true;

                if ($flags['q']) {
                    return $this->success();
                }
            }
        }

        $exitCode = $stderr !== '' ? 2 : ($matchFound ? 0 : 1);

        return new ExecResult(stdout: $output, stderr: $stderr, exitCode: $exitCode);
    }

    /**
     * @param  array<string, bool>  $flags
     */
    private function grepContent(string $content, string $label, string $regex, array $flags, bool $withName): ExecResult
    {
        $output = '';
        $matchCount = 0;

        foreach ($this->splitLines($content)['lines'] as $idx => $line) {
            if (SafePcreRegex::match($regex, $line) === $flags['v']) {
                continue;
            }

            $matchCount++;

            if ($flags['q'] || $flags['l']) {
                break;
            }

            $prefix = ($withName ? $label.':' : '').($flags['n'] ? ($idx + 1).':' : '');

            if (! $flags['o']) {
                $output .= $prefix.$line."\n";
            } elseif (! $flags['v']) {
                // -o prints each non-empty match on its own line.
                foreach (array_filter(SafePcreRegex::matchAll($regex, $line), fn (string $match): bool => $match !== '') as $match) {
                    $output .= $prefix.$match."\n";
                }
            }
        }

        if ($flags['q']) {
            $output = '';
        } elseif ($flags['l']) {
            $output = $matchCount > 0 ? $label."\n" : '';
        } elseif ($flags['c']) {
            $output = ($withName ? $label.':' : '').$matchCount."\n";
        }

        return new ExecResult(stdout: $output, stderr: '', exitCode: $matchCount > 0 ? 0 : 1);
    }

    /**
     * Several patterns (from -e or newlines) match when any of them does.
     *
     * @param  list<string>  $patterns
     * @param  array<string, bool>  $flags
     */
    private function buildRegex(array $patterns, array $flags): string
    {
        $alternatives = [];

        foreach (explode("\n", implode("\n", $patterns)) as $pattern) {
            $regex = $flags['F'] ? preg_quote($pattern, '/') : PosixRegex::toPcre($pattern, $flags['E']);

            if ($flags['x']) {
                $regex = '^(?:'.$regex.')$';
            } elseif ($flags['w']) {
                $regex = '(?<!\w)(?:'.$regex.')(?!\w)';
            }

            $alternatives[] = $regex;
        }

        return '/'.implode('|', $alternatives).'/'.($flags['i'] ? 'i' : '');
    }

    /**
     * @param  list<array{path: string, label: string, recursed: bool}>  $result
     */
    private function collectFiles(CommandContext $commandContext, string $path, string $label, bool $recursed, array &$result, string &$stderr): void
    {
        try {
            $isDirectory = $commandContext->fs->stat($path)->isDirectory;
        } catch (RuntimeException) {
            $isDirectory = false;
        }

        if (! $isDirectory) {
            // Missing operands are reported when the read fails.
            $result[] = ['path' => $path, 'label' => $label, 'recursed' => $recursed];

            return;
        }

        try {
            $entries = $commandContext->fs->readdirWithFileTypes($path);
        } catch (RuntimeException) {
            $stderr .= "grep: {$label}: Permission denied\n";

            return;
        }

        $prefix = $label === '' ? '' : rtrim($label, '/').'/';

        foreach ($entries as $entry) {
            // Like GNU grep -r, symlinks met while recursing are not followed.
            if (! $entry->isSymbolicLink) {
                $this->collectFiles($commandContext, rtrim($path, '/').'/'.$entry->name, $prefix.$entry->name, true, $result, $stderr);
            }
        }
    }
}
