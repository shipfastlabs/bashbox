<?php

declare(strict_types=1);

namespace BashBox\Interpreter;

use BashBox\Ast\AssignmentNode;
use BashBox\Ast\HereDocNode;
use BashBox\Ast\Parts\LiteralPart;
use BashBox\Ast\RedirectionNode;
use BashBox\Ast\WordNode;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\ExecResult;
use BashBox\Filesystem\FileSystemInterface;
use BashBox\Interpreter\Expansion\WordExpander;
use Closure;
use RuntimeException;

/** The shell's fd table: opening redirections onto it and routing output through it. */
final class Redirections
{
    /**
     * Open fds (0 travels as a StdinStream argument): '@1:<level>' is that capture level's stdout, '@2' the shell's stderr, else '@null', a file path or a StdinStream.
     *
     * @var array<int, string|StdinStream>
     */
    public array $fds = [1 => '@1:0', 2 => '@2'];

    /** Capture level of the innermost pipe or `$(...)`; output for an outer level waits in $passthrough */
    private int $captureLevel = 0;

    /** @var array<int, string> output written to an outer level's stdout from inside a capture, keyed by level */
    private array $passthrough = [];

    public function __construct(
        private readonly InterpreterState $interpreterState,
        private readonly FileSystemInterface $fileSystem,
        private readonly WordExpander $wordExpander,
        private readonly Assignments $assignments,
    ) {}

    /**
     * Starts a capture level (a pipe stage, `$(...)`): fd 1, and fd 2 for `|&`, now go to the capture.
     *
     * @return array<int, string|StdinStream> the fds to hand back to leaveCaptureLevel()
     */
    public function enterCaptureLevel(bool $stderrToo): array
    {
        $savedFds = $this->fds;
        $this->fds[1] = '@1:'.++$this->captureLevel;

        if ($stderrToo) {
            $this->fds[2] = $this->fds[1];
        }

        return $savedFds;
    }

    /**
     * Ends a capture level, returning what was sent to the enclosing level's stdout meanwhile (`exec 3>&1; x=$(echo hi >&3)`).
     *
     * @param  array<int, string|StdinStream>  $savedFds
     */
    public function leaveCaptureLevel(array $savedFds): string
    {
        $this->fds = $savedFds;
        $this->captureLevel--;
        $passthrough = $this->passthrough[$this->captureLevel] ?? '';
        unset($this->passthrough[$this->captureLevel]);

        return $passthrough;
    }

    /**
     * After a command, puts back the fds its redirections changed; other changes (`exec 3>f` inside it) stay.
     *
     * @param  array<int, string|StdinStream>  $saved
     * @param  array<int, string|StdinStream>  $installed
     */
    public function restore(array $saved, array $installed): void
    {
        foreach (array_keys($saved + $installed) as $fd) {
            if (($saved[$fd] ?? null) === ($installed[$fd] ?? null)) {
                continue;
            }

            if (isset($saved[$fd])) {
                $this->fds[$fd] = $saved[$fd];
            } else {
                unset($this->fds[$fd]);
            }
        }
    }

    /**
     * Opens redirections left to right on top of the fd table, as bash does, so `>f 2>&1` and `2>&1 >f` differ.
     *
     * @param  list<RedirectionNode>  $redirections
     * @return array{stdin: ?StdinStream, fds: array<int, string|StdinStream>}|ExecResult ExecResult when a target can't be opened
     */
    public function open(array $redirections, StdinStream $stdinStream): array|ExecResult
    {
        $stdin = null;
        $fds = $this->fds;

        foreach ($redirections as $redirection) {
            $op = $redirection->operator;
            $fd = $this->limitFd($redirection->fd ?? 1);
            $named = $redirection->fdVariable;

            if ($redirection->target instanceof HereDocNode) {
                $content = $redirection->target->quoted
                    ? WordExpander::literalText($redirection->target->content)
                    : $this->wordExpander->expandHeredoc($redirection->target->content);

                if ($redirection->target->stripTabs) {
                    $content = preg_replace('/^\t+/m', '', $content) ?? $content;
                }

                if (strlen($content) > $this->interpreterState->limits->maxHereDocSize) {
                    throw new ExecutionLimitException(sprintf('Here-document size limit exceeded (%d bytes)', $this->interpreterState->limits->maxHereDocSize));
                }

                $opened = new StdinStream($content);
            } else {
                $target = $this->wordExpander->expand($redirection->target);
                $duplicate = ($op === '>&' || $op === '<&') && ($target === '-' || ctype_digit($target));
                $opened = match (true) {
                    $op === '<<<' => new StdinStream($target."\n"),
                    $op === '<', $op === '<>' => $this->openInputFile($target, $op === '<>'),
                    $duplicate => $this->duplicateFd($target, $fds, $stdin ?? $stdinStream, $this->badFdLabel($redirection, WordExpander::literalText($redirection->target))),
                    // `>&file` is the csh spelling of `&>file`
                    $op === '<&' || ($op === '>&' && ($fd !== 1 || $named !== null)) => new ExecResult(stderr: 'bash: '.($named ?? $target).": ambiguous redirect\n", exitCode: 1),
                    default => $this->openOutputFile($target, str_ends_with($op, '>>'), $op === '>|', $fds),
                };

                if (($op === '>&' && ! $duplicate) || $op === '&>' || $op === '&>>') {
                    $fd = -1; // both 1 and 2
                }
            }

            if ($named !== null && ! $opened instanceof ExecResult) {
                $namedFd = $opened === null ? $this->namedFdToClose($named) : $this->assignNamedFd($named, $fds);

                if ($namedFd instanceof ExecResult) {
                    return $this->route($namedFd, fds: $fds);
                }

                // `{name}>file` outlives the command, as in bash, so it goes straight into the shell's table
                $fd = $namedFd;

                if ($opened === null) {
                    unset($this->fds[$fd]);
                } else {
                    $this->fds[$fd] = $opened;
                }
            }

            // A failure is reported through the fds opened so far, so `2>/dev/null >/bad/f` stays quiet
            if ($opened instanceof ExecResult) {
                return $this->route($opened, fds: $fds);
            }

            if ($fd === 0) {
                // fd 0 travels as the StdinStream argument; a closed or write-only one can't be read
                $stdin = $opened instanceof StdinStream ? $opened : new StdinStream(readable: false);
            } elseif ($opened === null) {
                unset($fds[$fd]);
            } elseif ($fd === -1) {
                $fds[1] = $fds[2] = $opened;
            } else {
                $fds[$fd] = $opened;
            }
        }

        return ['stdin' => $stdin, 'fds' => $fds];
    }

    /**
     * Sends a result's output to the fds' targets and returns what's for the current capture level; $writer ('bash: echo') reports a failed write.
     *
     * @param  array<int, string|StdinStream>|null  $fds  the shell's fd table when null
     */
    public function route(ExecResult $execResult, ?string $writer = null, ?array $fds = null): ExecResult
    {
        // Output is buffered, not streamed, so a file shared by fd 1 and 2 gets all stdout and then all stderr
        $fds ??= $this->fds;
        $stdout = '';
        $stderr = '';
        $errors = $execResult->stderr;
        $exitCode = $execResult->exitCode;

        $error = $this->writeFd($fds[1] ?? null, $execResult->stdout, $stdout, $stderr);

        if ($error !== null && $writer !== null) {
            $errors .= sprintf("%s: write error: %s\n", $writer, $error);
            $exitCode = 1;
        }

        $this->writeFd($fds[2] ?? null, $errors, $stdout, $stderr);

        return new ExecResult($stdout, $stderr, $exitCode);
    }

    /** @return ?string strerror's text when the filesystem refuses */
    public function tryFs(Closure $write): ?string
    {
        try {
            $write();
        } catch (RuntimeException $runtimeException) {
            return self::strerror($runtimeException);
        }

        return null;
    }

    /** What strerror() says for a filesystem failure such as "ENOSPC: no space left on device, write '/f'" */
    public static function strerror(RuntimeException $runtimeException): string
    {
        $message = $runtimeException->getMessage();

        // bash can't put a NUL in a path, so a path holding one stays an exception
        if (str_contains($message, 'null byte')) {
            throw $runtimeException;
        }

        return match (strstr($message, ':', true)) {
            'EACCES' => 'Permission denied',
            'EISDIR' => 'Is a directory',
            'EPERM' => 'Operation not permitted',
            default => ucfirst((string) preg_replace('/^\w+: ([^,]*).*$/s', '$1', $message)),
        };
    }

    private function limitFd(int $fd): int
    {
        if ($fd >= $this->interpreterState->limits->maxFileDescriptors) {
            throw new ExecutionLimitException(sprintf('File descriptor limit exceeded (%d)', $this->interpreterState->limits->maxFileDescriptors));
        }

        return $fd;
    }

    /** The fd `{name}>&-` closes: the number in the variable */
    private function namedFdToClose(string $name): int|ExecResult
    {
        $value = $this->wordExpander->expand(new WordNode([new LiteralPart('${'.$name.'}')]));

        return ctype_digit($value) ? (int) $value : new ExecResult(stderr: "bash: {$name}: ambiguous redirect\n", exitCode: 1);
    }

    /**
     * `{name}>file`: the lowest fd from 10 that isn't open, stored in the variable (or array element)
     *
     * @param  array<int, string|StdinStream>  $fds
     */
    private function assignNamedFd(string $name, array $fds): int|ExecResult
    {
        $variable = explode('[', $name)[0];

        if ($this->interpreterState->isReadonly($variable)) {
            return new ExecResult(stderr: "bash: {$variable}: readonly variable\nbash: {$name}: cannot assign fd to variable\n", exitCode: 1);
        }

        $fd = 10;

        while (isset($fds[$fd])) {
            $this->limitFd(++$fd);
        }

        $this->assignments->apply($this->assignments->resolve(new AssignmentNode($name, new WordNode([new LiteralPart((string) $fd)]))));

        return $fd;
    }

    /** `<file` and `<>file` (which creates a missing file): a stream on the file that reads its current content */
    private function openInputFile(string $target, bool $readWrite): StdinStream|ExecResult
    {
        if ($target === '/dev/null') {
            return new StdinStream;
        }

        $path = $this->fileSystem->resolvePath($this->interpreterState->cwd, $target);

        try {
            if ($readWrite && ! $this->fileSystem->exists($path)) {
                $this->fileSystem->writeFile($path, '');
            }

            // `<dir` fails here, where bash opens it and fails the first read
            return new StdinStream($this->fileSystem->readFile($path), $this->fileSystem, $path, $readWrite);
        } catch (RuntimeException $runtimeException) {
            return $this->openError($target, $runtimeException);
        }
    }

    private function openError(string $target, RuntimeException $runtimeException): ExecResult
    {
        return new ExecResult(stderr: sprintf("bash: %s: %s\n", $target, self::strerror($runtimeException)), exitCode: 1);
    }

    /**
     * `N>&M` / `N<&M`: fd N becomes a copy of fd M, sharing its offset; `-` closes N (null).
     *
     * @param  array<int, string|StdinStream>  $fds
     */
    private function duplicateFd(string $target, array $fds, StdinStream $stdinStream, string $label): string|StdinStream|ExecResult|null
    {
        return match (true) {
            $target === '-' => null,
            $target === '0' => $stdinStream->dup(),
            default => $fds[(int) $target] ?? new ExecResult(stderr: "bash: {$label}: Bad file descriptor\n", exitCode: 1),
        };
    }

    /** What bash names when `>&M` finds M closed: a literal number or the word as written, the fd otherwise, or `{name}`'s variable */
    private function badFdLabel(RedirectionNode $redirectionNode, string $raw): string
    {
        $default = ctype_digit($raw) || $redirectionNode->fd === ($redirectionNode->operator === '>&' ? 1 : 0);

        return $redirectionNode->fdVariable ?? ($default ? $raw : (string) $redirectionNode->fd);
    }

    /**
     * Creates or truncates the file now, so route() only ever appends.
     *
     * @param  array<int, string|StdinStream>  $fds
     */
    private function openOutputFile(string $target, bool $append, bool $clobber, array $fds): string|StdinStream|ExecResult
    {
        $special = ['/dev/null' => '@null', '/dev/stdout' => $fds[1] ?? '@null', '/dev/stderr' => $fds[2] ?? '@null'];

        if (isset($special[$target])) {
            return $special[$target];
        }

        $path = $this->fileSystem->resolvePath($this->interpreterState->cwd, $target);

        if (! $append && ! $clobber && $this->interpreterState->shellOpts['noclobber'] && $this->fileSystem->exists($path)) {
            return new ExecResult(stderr: "bash: {$target}: cannot overwrite existing file\n", exitCode: 1);
        }

        try {
            // The filesystem would create missing parents; a redirection must not
            if (! $this->fileSystem->stat(dirname($path))->isDirectory) {
                return new ExecResult(stderr: "bash: {$target}: Not a directory\n", exitCode: 1);
            }

            $append ? $this->fileSystem->appendFile($path, '') : $this->fileSystem->writeFile($path, '');
        } catch (RuntimeException $runtimeException) {
            return $this->openError($target, $runtimeException);
        }

        return $path;
    }

    /** @return ?string why the write failed: $target isn't open for writing, or the filesystem refused */
    private function writeFd(string|StdinStream|null $target, string $data, string &$stdout, string &$stderr): ?string
    {
        if ($data === '' || $target === '@null') {
            return null;
        }

        if ($target instanceof StdinStream && $target->writable) {
            return $this->tryFs(fn () => $target->write($data));
        }

        if (! is_string($target)) {
            return 'Bad file descriptor';
        }

        if ($target === '@2') {
            $stderr .= $data;
        } elseif (str_starts_with($target, '@1:')) {
            $level = (int) substr($target, 3);

            if ($level === $this->captureLevel) {
                $stdout .= $data;
            } else {
                $this->passthrough[$level] = ($this->passthrough[$level] ?? '').$data;
            }
        } else {
            $this->interpreterState->limitOutput(strlen($data));

            return $this->tryFs(fn () => $this->fileSystem->appendFile($target, $data));
        }

        return null;
    }
}
