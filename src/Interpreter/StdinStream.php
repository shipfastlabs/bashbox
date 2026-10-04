<?php

declare(strict_types=1);

namespace BashBox\Interpreter;

use BashBox\Filesystem\FileSystemInterface;
use RuntimeException;

/**
 * Shared, consumable stdin: `read` takes a line, a command that reads stdin takes the rest,
 * so every statement in a loop body sees what the previous one left behind.
 *
 * Also an open file description for `exec 3<file` / `3<>file`: a file-backed stream reads the
 * file's current content, and a writable one writes at the shared offset like a real fd.
 */
final class StdinStream
{
    private int $offset = 0;

    /** Where reads go once `exec <file` redirected this stream (the fd 0 slot) elsewhere */
    private ?StdinStream $stdinStream = null;

    public function __construct(
        private string $data = '',
        private readonly ?FileSystemInterface $fileSystem = null,
        private readonly string $path = '',
        public readonly bool $writable = false,
        private readonly bool $readable = true,
    ) {}

    /** False for an fd 0 that is closed or open only for writing (`read x <&-`) */
    public function isReadable(): bool
    {
        return $this->stream()->readable;
    }

    /**
     * Returns the next record without its delimiter, or null at EOF. $terminated reports
     * whether the delimiter was found (bash's `read` fails on an unterminated last line).
     *
     * @param-out bool $terminated
     */
    public function readLine(string $delimiter = "\n", ?bool &$terminated = null): ?string
    {
        $stream = $this->stream();
        $data = $stream->data();

        if ($stream->offset >= strlen($data)) {
            $terminated = false;

            return null;
        }

        $pos = strpos($data, $delimiter === '' ? "\0" : $delimiter, $stream->offset);
        $terminated = $pos !== false;

        if ($pos === false) {
            return $stream->readAll();
        }

        $line = substr($data, $stream->offset, $pos - $stream->offset);
        $stream->offset = $pos + max(1, strlen($delimiter));

        return $line;
    }

    /**
     * `read -n`/`-N`: consumes up to $count characters, stopping early at an unescaped $delimiter
     * (consumed, not returned; null for -N) or EOF. A backslash and the character it escapes count
     * as one, and backslash-newline as none, unless $raw. Returns the text as read, escapes included.
     *
     * @param-out bool $terminated false when EOF came first
     */
    public function readChars(int $count, ?string $delimiter, bool $raw, ?bool &$terminated = null): string
    {
        $stream = $this->stream();
        $data = $stream->data();
        $len = strlen($data);
        $start = $stream->offset;
        $i = $start;
        $read = 0;
        $terminated = true;

        while ($read < $count) {
            if ($i >= $len || (! $raw && $data[$i] === '\\' && $i + 1 >= $len)) {
                // A backslash with nothing after it is dropped along with the EOF
                $terminated = false;
                $stream->offset = $len;

                return substr($data, $start, $i - $start);
            }

            if ($delimiter !== null && $data[$i] === ($delimiter === '' ? "\0" : $delimiter)) {
                $stream->offset = $i + 1;

                return substr($data, $start, $i - $start);
            }

            $escaped = ! $raw && $data[$i] === '\\';
            $read += $escaped && $data[$i + 1] === "\n" ? 0 : 1;
            $i += $escaped ? 2 : 1;
        }

        $stream->offset = $i;

        return substr($data, $start, $i - $start);
    }

    public function readAll(): string
    {
        $stream = $this->stream();
        $rest = substr($stream->data(), $stream->offset);
        $stream->offset += strlen($rest);

        return $rest;
    }

    /** Writes at the shared offset, overwriting in place like a file opened with `<>` */
    public function write(string $text): void
    {
        $data = str_pad($this->data(), $this->offset, "\0");
        $this->data = substr_replace($data, $text, $this->offset, strlen($text));
        $this->offset += strlen($text);
        $this->fileSystem?->writeFile($this->path, $this->data);
    }

    /**
     * The open stream behind this fd 0 slot, for `3<&0`: later redirecting this slot (`exec <file`)
     * must not change what the duplicate reads.
     */
    public function dup(): self
    {
        return $this->stdinStream ??= clone $this;
    }

    /** `exec <file`: from now on this slot reads from $stream */
    public function redirect(self $stream): void
    {
        $this->stdinStream = $stream;
    }

    private function stream(): self
    {
        return $this->stdinStream?->stream() ?? $this;
    }

    private function data(): string
    {
        try {
            // A file-backed stream sees writes made through other fds; an unlinked file keeps its last content
            $this->data = $this->fileSystem?->readFile($this->path) ?? $this->data;
        } catch (RuntimeException) {
        }

        return $this->data;
    }
}
