<?php

declare(strict_types=1);

namespace BashBox\Commands\Input;

final readonly class InputContent
{
    /**
     * @param  string  $content  The concatenated content
     * @param  list<array{name: string, content: string}>  $files  Individual file contents with metadata
     * @param  InputSource  $source  Where the input came from
     */
    public function __construct(
        public string $content,
        public array $files,
        public InputSource $source,
    ) {}

    public function isMultiFile(): bool
    {
        return $this->source === InputSource::MULTIPLE_FILES;
    }

    public function isStdin(): bool
    {
        return $this->source === InputSource::STDIN;
    }

    /**
     * @return list<string>
     */
    public function getFileNames(): array
    {
        return array_map(fn (array $f): string => $f['name'], $this->files);
    }
}
