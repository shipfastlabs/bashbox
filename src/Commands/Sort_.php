<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Sort_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'sort';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->parseFlags($args, [
            'r' => false,
            'n' => false,
            'u' => false,
            'k' => '',
            't' => '',
        ]);

        $flags = $parsed['flags'];
        $files = $parsed['args'];

        $input = '';

        if ($files !== []) {
            foreach ($files as $file) {
                $path = $this->resolvePath($commandContext, $file);

                try {
                    $input .= $commandContext->fs->readFile($path);
                } catch (RuntimeException) {
                    return $this->failure("sort: cannot read: {$file}: No such file or directory\n");
                }
            }
        } else {
            $input = $commandContext->stdin;
        }

        if ($input === '') {
            return $this->success('');
        }

        ['lines' => $lines, 'trailingNewline' => $trailingNewline] = $this->splitLines($input);

        $delimiter = $flags['t'] !== '' && $flags['t'] !== false ? (string) $flags['t'] : '';
        $keySpec = $flags['k'] !== '' && $flags['k'] !== false ? (string) $flags['k'] : '';

        usort($lines, function (string $a, string $b) use ($flags, $delimiter, $keySpec): int {
            $aKey = $this->extractKey($a, $keySpec, $delimiter);
            $bKey = $this->extractKey($b, $keySpec, $delimiter);

            if ($flags['n']) {
                $aNum = $this->extractLeadingNumber($aKey);
                $bNum = $this->extractLeadingNumber($bKey);
                $cmp = $aNum <=> $bNum;
            } else {
                $cmp = strcmp($aKey, $bKey);
            }

            return $flags['r'] ? -$cmp : $cmp;
        });

        if ($flags['u']) {
            $lines = $this->uniqueLines($lines, $flags, $delimiter, $keySpec);
        }

        $output = implode("\n", $lines);

        if ($trailingNewline || $output !== '') {
            $output .= "\n";
        }

        return $this->success($output);
    }

    private function extractKey(string $line, string $keySpec, string $delimiter): string
    {
        if ($keySpec === '') {
            return $line;
        }

        // Parse key spec like "2" or "2,3"
        $parts = explode(',', $keySpec);
        $startField = max(1, (int) $parts[0]);
        $endField = isset($parts[1]) ? (int) $parts[1] : $startField;

        $sep = $delimiter !== '' ? $delimiter : ' ';

        if ($delimiter !== '') {
            $fields = explode($sep, $line);
        } else {
            // Default: split on whitespace runs
            $fields = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $result = [];

        for ($i = $startField - 1; $i < $endField && $i < count($fields); $i++) {
            $result[] = $fields[$i];
        }

        return implode($sep, $result);
    }

    private function extractLeadingNumber(string $s): float
    {
        $s = ltrim($s);

        if (preg_match('/^[+-]?\d+(\.\d+)?/', $s, $m)) {
            return (float) $m[0];
        }

        return 0.0;
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, string|bool>  $flags
     * @return list<string>
     */
    private function uniqueLines(array $lines, array $flags, string $delimiter, string $keySpec): array
    {
        $result = [];
        $seen = [];

        foreach ($lines as $line) {
            $key = $this->extractKey($line, $keySpec, $delimiter);

            if ($flags['n']) {
                $key = (string) $this->extractLeadingNumber($key);
            }

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $line;
            }
        }

        return $result;
    }
}
