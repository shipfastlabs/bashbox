<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Cut extends AbstractCommand
{
    public function getName(): string
    {
        return 'cut';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $complement = false;
        $filteredArgs = [];

        foreach ($args as $arg) {
            if ($arg === '--complement') {
                $complement = true;
            } else {
                $filteredArgs[] = $arg;
            }
        }

        $parsed = $this->parseFlags($filteredArgs, [
            'd' => '',
            'f' => '',
            'b' => '',
            'c' => '',
        ]);

        $flags = $parsed['flags'];
        $files = $parsed['args'];

        $bytesSpec = (string) $flags['b'];
        $fieldsSpec = (string) $flags['f'];
        $charsSpec = (string) $flags['c'];

        /** @var non-empty-string $delimiter */
        $delimiter = $flags['d'] !== '' && $flags['d'] !== false ? (string) $flags['d'] : "\t";

        if ($fieldsSpec === '' && $charsSpec === '' && $bytesSpec === '') {
            return $this->failure("cut: you must specify a list of bytes, characters, or fields\n");
        }

        $specCount = ($fieldsSpec !== '' ? 1 : 0) + ($charsSpec !== '' ? 1 : 0) + ($bytesSpec !== '' ? 1 : 0);

        if ($specCount > 1) {
            return $this->failure("cut: only one type of list may be specified\n");
        }

        $input = '';

        if ($files !== []) {
            foreach ($files as $file) {
                if ($file === '-') {
                    $input .= $commandContext->stdin;
                } else {
                    $path = $this->resolvePath($commandContext, $file);

                    try {
                        $input .= $commandContext->fs->readFile($path);
                    } catch (RuntimeException) {
                        return $this->failure("cut: {$file}: No such file or directory\n");
                    }
                }
            }
        } else {
            $input = $commandContext->stdin;
        }

        if ($input === '') {
            return $this->success('');
        }

        ['lines' => $lines] = $this->splitLines($input);

        $output = '';

        if ($bytesSpec !== '') {
            $positions = $this->parseRangeSpec($bytesSpec);

            foreach ($lines as $line) {
                $bytes = str_split($line);
                $selected = [];

                foreach ($bytes as $index => $byte) {
                    $position = $index + 1;

                    if ($this->shouldSelectPosition($position, $positions, $complement)) {
                        $selected[] = $byte;
                    }
                }

                $output .= implode('', $selected)."\n";
            }
        } elseif ($charsSpec !== '') {
            $positions = $this->parseRangeSpec($charsSpec);

            foreach ($lines as $line) {
                $chars = mb_str_split($line);
                $selected = [];

                foreach ($chars as $index => $char) {
                    $position = $index + 1;

                    if ($this->shouldSelectPosition($position, $positions, $complement)) {
                        $selected[] = $char;
                    }
                }

                $output .= implode('', $selected)."\n";
            }
        } else {
            $fieldPositions = $this->parseRangeSpec($fieldsSpec);

            foreach ($lines as $line) {
                if (! str_contains($line, $delimiter)) {
                    // Lines without delimiter are printed as-is
                    $output .= $line."\n";

                    continue;
                }

                $fields = explode($delimiter, $line);
                $selected = [];

                foreach ($fields as $index => $field) {
                    $position = $index + 1;

                    if ($this->shouldSelectPosition($position, $fieldPositions, $complement)) {
                        $selected[] = $field;
                    }
                }

                $output .= implode($delimiter, $selected)."\n";
            }
        }

        return $this->success($output);
    }

    /**
     * Parse a range specification like "1,3", "1-3", "2-", "-4", "1,3-5"
     *
     * @return list<int>
     */
    private function parseRangeSpec(string $spec): array
    {
        $positions = [];
        $parts = explode(',', $spec);

        foreach ($parts as $part) {
            $part = trim($part);

            if (str_contains($part, '-')) {
                $range = explode('-', $part, 2);
                $start = $range[0] !== '' ? (int) $range[0] : 1;
                $end = $range[1] !== '' ? (int) $range[1] : 10000;

                for ($i = $start; $i <= min($end, 10000); $i++) {
                    $positions[] = $i;
                }
            } else {
                $positions[] = (int) $part;
            }
        }

        sort($positions);

        return array_values(array_unique($positions));
    }

    /**
     * @param  list<int>  $positions
     */
    private function shouldSelectPosition(int $position, array $positions, bool $complement): bool
    {
        $isSelected = in_array($position, $positions, true);

        return $complement ? ! $isSelected : $isSelected;
    }
}
