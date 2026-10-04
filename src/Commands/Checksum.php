<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use InvalidArgumentException;
use RuntimeException;

/** md5sum, sha1sum and sha256sum. */
final class Checksum extends AbstractCommand
{
    private const array LONG = [
        'binary' => ['b', false],
        'check' => ['c', false],
        'tag' => ['tag', false],
        'text' => ['t', false],
        'ignore-missing' => ['ignore-missing', false],
        'quiet' => ['quiet', false],
        'status' => ['status', false],
        'strict' => ['strict', false],
        'warn' => ['w', false],
    ];

    private string $stdout = '';

    private string $stderr = '';

    /** @param 'md5'|'sha1'|'sha256' $algorithm */
    public function __construct(private readonly string $algorithm) {}

    public function getName(): string
    {
        return $this->algorithm.'sum';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        try {
            [$options, $files] = Getopt::parse($args, 'bctw', self::LONG);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->usageError($invalidArgumentException->getMessage());
        }

        $flags = array_column($options, 1, 0);
        $check = isset($flags['c']);
        // -b and -t override each other; the last one given wins
        $modes = array_values(array_filter(array_column($options, 0), fn (string $option): bool => $option === 'b' || $option === 't'));
        $binary = end($modes) ?: null;

        $misuse = match (true) {
            isset($flags['tag']) && $binary === 't' => '--tag does not support --text mode',
            isset($flags['tag']) && $check => 'the --tag option is meaningless when verifying checksums',
            $binary !== null && $check => 'the --binary and --text options are meaningless when verifying checksums',
            default => null,
        };

        foreach (['ignore-missing' => 'ignore-missing', 'status' => 'status', 'w' => 'warn', 'quiet' => 'quiet', 'strict' => 'strict'] as $flag => $option) {
            $misuse ??= isset($flags[$flag]) && ! $check ? sprintf('the --%s option is meaningful only when verifying checksums', $option) : null;
        }

        if ($misuse !== null) {
            return $this->usageError($misuse);
        }

        $this->stdout = '';
        $this->stderr = '';
        $ok = true;

        foreach ($files ?: ['-'] as $file) {
            $content = $this->read($commandContext, $file, $check);

            if ($content === null) {
                $ok = false;
            } elseif ($check) {
                $ok = $this->verify($commandContext, $file, $content, $flags) && $ok;
            } else {
                $hash = hash($this->algorithm, $content);
                [$escaped, $name] = $this->escape($file);
                $this->stdout .= isset($flags['tag'])
                    ? sprintf("%s%s (%s) = %s\n", $escaped, strtoupper($this->algorithm), $name, $hash)
                    : sprintf("%s%s %s%s\n", $escaped, $hash, $binary === 'b' ? '*' : ' ', $name);
            }
        }

        return new ExecResult($this->stdout, $this->stderr, $ok ? 0 : 1);
    }

    /**
     * Checks every line of a checksum file, reporting like GNU.
     *
     * @param  array<string, string>  $flags
     */
    private function verify(CommandContext $commandContext, string $file, string $content, array $flags): bool
    {
        $status = isset($flags['status']);
        $tag = strtoupper($this->algorithm);
        $length = strlen(hash($this->algorithm, ''));
        $lines = explode("\n", $content);
        $counts = ['formatted' => 0, 'misformatted' => 0, 'unread' => 0, 'mismatched' => 0, 'matched' => 0];

        foreach ($lines as $number => $line) {
            $line = ltrim(rtrim($line, "\r"), " \t");

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $parsed = preg_match('/^(\\\\?)'.$tag.' \((.*)\) = ([\da-f]{'.$length.'})$/i', $line, $m) === 1
                || preg_match('/^(\\\\?)()([\da-f]{'.$length.'})[ \t][ *]?(.+)$/i', $line, $m) === 1;
            $name = $parsed ? ($m[4] ?? $m[2]) : '';

            if ($parsed && $m[1] !== '') {
                $parsed = preg_match('/^(?:[^\\\\]|\\\\[\\\\nr])*$/', $name) === 1;
                $name = strtr($name, ['\\\\' => '\\', '\\n' => "\n", '\\r' => "\r"]);
            }

            if (! $parsed) {
                $counts['misformatted']++;
                $this->stderr .= isset($flags['w']) ? sprintf("%s: %s: %d: improperly formatted %s checksum line\n", $this->getName(), $this->quote($file), $number + 1, $tag) : '';

                continue;
            }

            $counts['formatted']++;
            if (isset($flags['ignore-missing']) && $name !== '-' && ! $commandContext->fs->exists($this->resolvePath($commandContext, $name))) {
                continue;
            }

            $data = $this->read($commandContext, $name);
            $display = implode('', $this->escape($name));

            if ($data === null) {
                $counts['unread']++;
                $this->stdout .= $status ? '' : $display.": FAILED open or read\n";
            } elseif (hash($this->algorithm, $data) === strtolower($m[3])) {
                $counts['matched']++;
                $this->stdout .= $status || isset($flags['quiet']) ? '' : $display.": OK\n";
            } else {
                $counts['mismatched']++;
                $this->stdout .= $status ? '' : $display.": FAILED\n";
            }
        }

        if ($counts['formatted'] === 0) {
            $this->stderr .= sprintf("%s: %s: no properly formatted checksum lines found\n", $this->getName(), $this->quote($file));

            return false;
        }

        if (! $status) {
            $this->warn($counts['misformatted'], 'line is improperly formatted', 'lines are improperly formatted');
            $this->warn($counts['unread'], 'listed file could not be read', 'listed files could not be read');
            $this->warn($counts['mismatched'], 'computed checksum did NOT match', 'computed checksums did NOT match');
            $this->stderr .= isset($flags['ignore-missing']) && $counts['matched'] === 0 ? sprintf("%s: %s: no file was verified\n", $this->getName(), $this->quote($file)) : '';
        }

        return $counts['matched'] > 0 && $counts['mismatched'] === 0 && $counts['unread'] === 0 && ($counts['misformatted'] === 0 || ! isset($flags['strict']));
    }

    private function warn(int $count, string $singular, string $plural): void
    {
        $this->stderr .= $count === 0 ? '' : sprintf("%s: WARNING: %d %s\n", $this->getName(), $count, $count === 1 ? $singular : $plural);
    }

    /** The file's contents, or null after reporting why it can't be read. */
    private function read(CommandContext $commandContext, string $file, bool $checksumFile = false): ?string
    {
        try {
            return $this->readOperand($commandContext, $file);
        } catch (RuntimeException $runtimeException) {
            $error = $this->describeError($runtimeException);
            // GNU reads a checksum file line by line and reports a failed read without its cause
            $this->stderr .= sprintf("%s: %s: %s\n", $this->getName(), $this->quote($file), $checksumFile && $error === 'Is a directory' ? 'read error' : $error);

            return null;
        }
    }

    /**
     * A name with a backslash or line break is escaped, and its line starts with a backslash to say so.
     *
     * @return array{string, string}
     */
    private function escape(string $name): array
    {
        $escaped = strtr($name, ['\\' => '\\\\', "\n" => '\\n', "\r" => '\\r']);

        return [$escaped === $name ? '' : '\\', $escaped];
    }

    private function quote(string $file): string
    {
        return $this->shellEscape($file === '-' ? 'standard input' : $file);
    }
}
