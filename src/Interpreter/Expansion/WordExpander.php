<?php

declare(strict_types=1);

namespace BashBox\Interpreter\Expansion;

use BashBox\Ast\Parts\LiteralPart;
use BashBox\Ast\WordNode;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Exceptions\ExpansionException;
use BashBox\Exceptions\ParseException;
use BashBox\Exceptions\UnboundVariableException;
use BashBox\ExecResult;
use BashBox\Interpreter\Interpreter;
use BashBox\Interpreter\InterpreterState;
use BashBox\Parser\Lexer;
use Closure;
use RuntimeException;

final class WordExpander
{
    public function __construct(
        private readonly InterpreterState $interpreterState,
        private readonly Interpreter $interpreter,
    ) {}

    // Field-mode markers: while expandToList() runs, quoted text is wrapped in Q_OPEN/Q_CLOSE,
    // unquoted expansion results in X_OPEN/X_CLOSE (the only text IFS may split), and "$@"
    // elements are separated by BREAK. splitFields() turns that into words in one pass.
    // Text itself never holds a raw \x00-\x06 byte there: esc() writes each as \x00 plus the byte + 0x40.
    private const string Q_OPEN = "\x01";

    private const string Q_CLOSE = "\x02";

    private const string BREAK = "\x03";

    private const string EMPTY_AT = "\x04";

    private const string X_OPEN = "\x05";

    private const string X_CLOSE = "\x06";

    private const string ESC_BYTE = "\x00";

    private const array ESCAPES = ["\x00" => "\x00@", "\x01" => "\x00A", "\x02" => "\x00B", "\x03" => "\x00C", "\x04" => "\x00D", "\x05" => "\x00E", "\x06" => "\x00F"];

    /** Characters a quoted section escapes in a pattern so they match literally */
    private const string PATTERN_CHARS = '*?[]\\()|@+!';

    private bool $markQuotes = false;

    private bool $inDoubleQuotes = false;

    /** Directory entries the current pathname expansion has read */
    private int $globOperations = 0;

    public function expand(WordNode $wordNode): string
    {
        return $this->withMode(false, function () use ($wordNode): string {
            $result = '';

            foreach ($wordNode->parts as $part) {
                $result .= $part instanceof LiteralPart ? $this->expandLiteralValue($part->value) : '';
            }

            return $this->interpreterState->limitString($result);
        });
    }

    /**
     * A pattern word, as in `case` and `[[ == ]]`: quoted characters come back backslash-escaped so they match literally.
     * With $regex it is the PCRE body of `[[ =~ ]]`, its quoted characters escaped for PCRE instead.
     */
    public function expandPattern(WordNode $wordNode, bool $regex = false): string
    {
        return $this->expandPatternWord(implode('', array_map(fn (\BashBox\Ast\WordPart $wordPart): string => $wordPart instanceof LiteralPart ? $wordPart->value : '', $wordNode->parts)), $regex);
    }

    /** Turns marked text into a glob pattern (or regex): quoted characters come back escaped so they match literally. */
    private function markedToPattern(string $marked, bool $regex = false): string
    {
        $pattern = '';
        $quoted = false;
        $len = strlen($marked);

        for ($i = 0; $i < $len; $i++) {
            $ch = $marked[$i];

            if ($ch === self::Q_OPEN || $ch === self::Q_CLOSE) {
                $quoted = $ch === self::Q_OPEN;

                continue;
            }

            if ($ch !== self::ESC_BYTE && str_contains(self::BREAK.self::EMPTY_AT.self::X_OPEN.self::X_CLOSE, $ch)) {
                $pattern .= $ch === self::BREAK ? ' ' : '';

                continue;
            }

            $ch = $ch === self::ESC_BYTE ? chr(ord($marked[++$i]) - 0x40) : $ch;
            $pattern .= match (true) {
                ! $quoted => $ch,
                $regex => preg_quote($ch, "\x01"),
                default => str_contains(self::PATTERN_CHARS, $ch) ? '\\'.$ch : $ch,
            };
        }

        return $pattern;
    }

    /** Text entering marked output: its bytes can't be mistaken for markers. Unmarked text passes as is. */
    private function esc(string $text): string
    {
        return $this->markQuotes ? strtr($text, self::ESCAPES) : $text;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withMode(bool $markQuotes, callable $callback): mixed
    {
        [$savedMark, $savedDq] = [$this->markQuotes, $this->inDoubleQuotes];
        [$this->markQuotes, $this->inDoubleQuotes] = [$markQuotes, false];

        try {
            return $callback();
        } finally {
            [$this->markQuotes, $this->inDoubleQuotes] = [$savedMark, $savedDq];
        }
    }

    /** Runs $(...) or `...`: stderr passes through and $? becomes the substitution's status, as in bash. */
    private function commandSubstitution(string $script, bool $backquoted = false): ExecResult
    {
        try {
            $result = $this->interpreter->execSubcommand($script, substitution: ! $backquoted);
        } catch (ParseException $parseException) {
            // bash parses $(...) with its line, so the script ends with 127; `...` is parsed only when it runs
            $result = $backquoted
                ? new ExecResult(stderr: 'bash: command substitution: '.$parseException->getMessage()."\n", exitCode: 2)
                : throw $this->syntaxError($parseException);
        }

        $this->interpreter->writeStderr($result->stderr);
        $this->interpreterState->lastExitCode = $result->exitCode;
        $this->interpreterState->substitutionStatus = $result->exitCode;

        return $result;
    }

    private function syntaxError(ParseException $parseException): ExpansionException
    {
        $message = $parseException->getMessage();

        return new ExpansionException('bash: '.$message.(str_contains($message, 'unexpected token') && ! str_ends_with($message, "`)'") ? " while looking for matching `)'" : ''), fatal: true);
    }

    private function quoted(string $text): string
    {
        return $this->markQuotes ? self::Q_OPEN.$this->esc($text).self::Q_CLOSE : $text;
    }

    /** Wraps an unquoted expansion result so splitFields() may split and glob it. */
    private function splittable(string $text): string
    {
        return $this->markQuotes && ! $this->inDoubleQuotes ? self::X_OPEN.$text.self::X_CLOSE : $text;
    }

    /** Marked text back to plain text; unmarked text has nothing to strip. */
    private function stripMarkers(string $text): string
    {
        if (! $this->markQuotes) {
            return $text;
        }

        return (string) preg_replace_callback('/\x00(.)|[\x01-\x06]/s', fn (array $m): string => match (true) {
            isset($m[1]) => chr(ord($m[1]) - 0x40),
            $m[0] === self::BREAK => ' ',
            default => '',
        }, $text);
    }

    /**
     * "$@" / "${a[@]}" inside double quotes become one word per element; everywhere else they join with a space.
     *
     * @param  list<string>  $items
     */
    private function joinAt(array $items): string
    {
        if (! $this->markQuotes || ! $this->inDoubleQuotes) {
            return $this->esc(implode(' ', $items));
        }

        return $items === [] ? self::EMPTY_AT : implode(self::BREAK, array_map($this->esc(...), $items));
    }

    /**
     * @return list<string>
     */
    private function splitFields(string $marked, string $ifs): array
    {
        // A quoted section holding only an empty "$@" produces no word at all
        $marked = str_replace(self::EMPTY_AT, '', preg_replace('/\x01\x04+\x02/', '', $marked) ?? $marked);
        $globEnabled = ! ($this->interpreterState->shellOpts['noglob'] ?? false);
        $extglob = $this->interpreterState->shopt['extglob'];
        $fields = [];
        $current = '';
        $pattern = '';

        /** @var bool $exists typed as bool, not false: the by-reference $flush closure below resets it */
        $exists = false;
        $quoted = false;
        $splittable = false;

        $flush = function () use (&$fields, &$current, &$pattern, &$exists, $globEnabled, $extglob): void {
            if ($exists) {
                array_push($fields, ...($globEnabled && Glob::isPattern($pattern, $extglob) ? $this->expandGlob($pattern) : [$current]));
                $this->interpreterState->limitCount(count($fields));
            }

            [$current, $pattern, $exists] = ['', '', false];
        };
        $len = strlen($marked);

        for ($i = 0; $i < $len; $i++) {
            $ch = $marked[$i];

            if ($ch === self::Q_OPEN || $ch === self::Q_CLOSE) {
                $quoted = $ch === self::Q_OPEN;
                $exists = true;

                continue;
            }

            if ($ch === self::X_OPEN || $ch === self::X_CLOSE) {
                $splittable = $ch === self::X_OPEN;

                continue;
            }

            if ($ch === self::BREAK) {
                $exists = true;
                $flush();
                $exists = true;

                continue;
            }

            $ch = $ch === self::ESC_BYTE ? chr(ord($marked[++$i]) - 0x40) : $ch;

            if (! $quoted && $splittable && str_contains($ifs, $ch)) {
                if (! ctype_space($ch)) {
                    $exists = true;
                }

                $flush();

                continue;
            }

            $current .= $ch;
            $pattern .= $quoted && str_contains(self::PATTERN_CHARS, $ch) ? '\\'.$ch : $ch;
            $exists = true;
        }

        $flush();

        return $fields;
    }

    /**
     * @return list<string>
     */
    public function expandToList(WordNode $wordNode): array
    {
        $ifs = $this->interpreterState->getVar('IFS') ?? " \t\n";
        $results = [];

        foreach ($this->withMode(true, fn (): array => $this->expandWordVariants($wordNode)) as $expanded) {
            array_push($results, ...$this->splitFields($this->interpreterState->limitString($expanded), $ifs));
            $this->interpreterState->limitCount(count($results));
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function expandWordVariants(WordNode $wordNode): array
    {
        $variants = [''];

        foreach ($wordNode->parts as $part) {
            $next = [];

            foreach ($variants as $variant) {
                foreach ($this->expandBraceString($part instanceof LiteralPart ? $part->value : '') as $suffix) {
                    $next[] = $variant.$this->expandLiteralValue($suffix);
                    $this->limitBraceResults(count($next));
                }
            }

            $variants = $next;
        }

        return $variants;
    }

    private function expandLiteralValue(string $value): string
    {
        // Process inline variable expansions within literal text
        // This handles $VAR, ${VAR}, ${VAR:-default}, $((expr)), $(cmd) in raw token text
        $result = '';
        $len = strlen($value);
        $i = 0;

        // Tilde expansion: the sandbox has no user database, so ~name stays literal like an unknown user in bash
        if ($value === '~' || str_starts_with($value, '~/')) {
            $result = $this->esc($this->interpreterState->getVar('HOME') ?? '/home/user');
            $i = 1;
        }

        while ($i < $len) {
            $ch = $value[$i];

            if ($ch === '$' && ($value[$i + 1] ?? '') === "'") {
                // $'...': ANSI-C escapes, then it's single-quoted text
                [$decoded, $i] = Lexer::ansiCQuoted($value, $i);
                $result .= $this->quoted($decoded);

                continue;
            }

            if ($ch === '$' && ($value[$i + 1] ?? '') === '"') {
                $i++; // $"..." (a translatable string) reads as "..."

                continue;
            }

            if ($ch === '$' && $i + 1 < $len) {
                $expanded = $this->expandDollarInLiteral($value, $i);
                $result .= $expanded['pos'] === $i + 1 ? $expanded['value'] : $this->splittable($expanded['value']);
                $i = $expanded['pos'];

                continue;
            }

            if ($ch === '\\' && $i + 1 < $len) {
                // In unquoted context, backslash-newline is line continuation (already handled by lexer)
                $result .= $this->quoted($value[$i + 1]);
                $i += 2;

                continue;
            }

            if ($ch === "'") {
                $end = strpos($value, "'", $i + 1);
                $end = $end === false ? $len : $end;
                $result .= $this->quoted(substr($value, $i + 1, $end - $i - 1));
                $i = $end + 1;

                continue;
            }

            if ($ch === '"') {
                $i++;
                $savedDq = $this->inDoubleQuotes;
                $this->inDoubleQuotes = true;
                $result .= ($this->markQuotes ? self::Q_OPEN : '').$this->expandQuotedText($value, $i, '"').($this->markQuotes ? self::Q_CLOSE : '');
                $this->inDoubleQuotes = $savedDq;
                $i++; // Skip the closing quote

                continue;
            }

            if ($ch === '`') {
                $result .= $this->backtick($value, $i);

                continue;
            }

            if (($ch === '<' || $ch === '>') && ($value[$i + 1] ?? '') === '(') {
                $end = $this->matchingEnd($value, $i, $ch.'(');

                try {
                    $result .= $this->esc($this->interpreter->processSubstitution($ch, substr($value, $i + 2, $end - $i - 3)));
                } catch (ParseException $parseException) {
                    throw $this->syntaxError($parseException);
                }

                $i = $end;

                continue;
            }

            $result .= $this->esc($ch);
            $i++;
        }

        return $result;
    }

    /** A here-document body: expansions run as in double quotes, but quotes are ordinary characters. */
    public function expandHeredoc(WordNode $wordNode): string
    {
        return $this->withMode(false, function () use ($wordNode): string {
            $this->inDoubleQuotes = true;
            $i = 0;

            return $this->interpreterState->limitString($this->expandQuotedText(implode('', array_map(fn (\BashBox\Ast\WordPart $wordPart): string => $wordPart instanceof LiteralPart ? $wordPart->value : '', $wordNode->parts)), $i, ''));
        });
    }

    /**
     * Text as inside double quotes, from $i up to $stop (or the end when it's ''): expansions run, and a
     * backslash only escapes $, `, \, a newline and $stop.
     */
    private function expandQuotedText(string $value, int &$i, string $stop): string
    {
        $result = '';
        $len = strlen($value);

        while ($i < $len && $value[$i] !== $stop) {
            if ($value[$i] === '$' && $i + 1 < $len) {
                $expanded = $this->expandDollarInLiteral($value, $i);
                $result .= $expanded['value'];
                $i = $expanded['pos'];
            } elseif ($value[$i] === '`') {
                $result .= $this->backtick($value, $i);
            } elseif ($value[$i] === '\\' && $i + 1 < $len && str_contains("$`\\\n".$stop, $value[$i + 1])) {
                $result .= $value[$i + 1] === "\n" ? '' : $value[$i + 1]; // none of these needs esc()
                $i += 2;
            } else {
                $result .= $this->esc($value[$i++]);
            }
        }

        return $result;
    }

    /** `cmd`, with $i on the opening backtick; inside, a backslash only escapes $, ` and \. */
    private function backtick(string $value, int &$i): string
    {
        $len = strlen($value);
        $cmd = '';

        for ($i++; $i < $len && $value[$i] !== '`'; $i++) {
            if ($value[$i] === '\\' && $i + 1 < $len && str_contains('$`\\', $value[$i + 1])) {
                $i++;
            }

            $cmd .= $value[$i];
        }

        $i++; // Skip the closing backtick

        return $this->splittable($this->esc(rtrim($this->commandSubstitution($cmd, backquoted: true)->stdout, "\n")));
    }

    /**
     * @return array{value: string, pos: int}
     */
    private function expandDollarInLiteral(string $value, int $i): array
    {
        $i++; // Skip $
        $ch = $value[$i];

        // $(( — arithmetic expansion, $( — command substitution
        if ($ch === '(') {
            $end = $this->matchingEnd($value, $i - 1, '$(');

            if (($value[$i + 1] ?? '') === '(') {
                $result = $this->interpreter->evaluateArithmeticString(substr($value, $i + 2, $end - $i - 4));

                return ['value' => (string) $result, 'pos' => $end];
            }

            $cmdResult = $this->commandSubstitution(substr($value, $i + 1, $end - $i - 2));

            return ['value' => $this->esc(rtrim($cmdResult->stdout, "\n")), 'pos' => $end];
        }

        if ($ch === '{') {
            $end = $this->matchingEnd($value, $i - 1, '${');

            return ['value' => $this->expandParameterString(substr($value, $i + 1, $end - $i - 2)), 'pos' => $end];
        }

        // $name, $1, $?, $@ ... (one digit only: $10 is ${1}0)
        if (preg_match('/\G(?:[a-zA-Z_]\w*|[0-9?!$#*@-])/', $value, $match, 0, $i) === 1) {
            return ['value' => $this->expandParameterString($match[0]), 'pos' => $i + strlen($match[0])];
        }

        return ['value' => '$', 'pos' => $i];
    }

    /** Position just past the substitution $open starts at $pos, found with the lexer's own quote-aware scan. */
    private function matchingEnd(string $value, int $pos, string $open): int
    {
        return Lexer::skipPast($value, $pos, $open, $this->interpreterState->limits);
    }

    /**
     * "$*" joins with the first IFS character; "$@" (and unquoted $*) keep one word per element.
     *
     * @param  list<string>  $items
     */
    private function joinPositional(string $kind, array $items): string
    {
        if ($kind === '*' && $this->inDoubleQuotes) {
            return $this->esc(implode(substr($this->interpreterState->getVar('IFS') ?? ' ', 0, 1), $items));
        }

        return $this->joinAt($items);
    }

    private function expandParameterString(string $content): string
    {
        if (preg_match('/^([#!]?)([a-zA-Z_]\w*|\d+|[@*#?$!-])(\[[^\]]*\])?(.*)$/s', $content, $m) !== 1) {
            throw new ExpansionException(sprintf('bash: ${%s}: bad substitution', $content));
        }

        [, $prefix, $name, $subscript, $rest] = $m;
        $subscript = $subscript === '' ? null : substr($subscript, 1, -1);
        $kind = $subscript ?? $name;
        $list = match (true) {
            $subscript === '@' || $subscript === '*' => $this->getOrderedArrayValues($name),
            $subscript === null && ($name === '@' || $name === '*') => $this->interpreterState->positionalParams,
            default => null,
        };

        if ($prefix === '#' && $rest === '') {
            return (string) ($list !== null ? count($list) : $this->charLength($this->boundValue($name, $subscript)));
        }

        if ($prefix === '!' && $rest === '') {
            if ($list !== null) {
                return $this->joinPositional($kind, array_map(strval(...), $this->getOrderedArrayKeys($name)));
            }

            // ${!ref} of a nameref is the name it ends up referring to
            if ($subscript === null && $this->interpreterState->hasAttribute($name, 'n')) {
                $target = $this->interpreterState->resolve($name, quiet: true) ?? $name;

                return $target === $name ? throw new ExpansionException(sprintf('bash: %s: invalid indirect expansion', $name)) : $this->esc($target);
            }

            $target = $this->lookup($name, $subscript) ?? '';

            return $target !== '' ? $this->expandParameterString($target) : throw new ExpansionException(sprintf('bash: %s: invalid indirect expansion', $name));
        }

        if ($prefix !== '') {
            throw new ExpansionException(sprintf('bash: ${%s}: bad substitution', $content));
        }

        if ($rest === '') {
            return $list !== null ? $this->joinPositional($kind, $list) : $this->esc($this->boundValue($name, $subscript));
        }

        $value = $list !== null ? ($list === [] ? null : implode(' ', $list)) : $this->lookup($name, $subscript);

        if (preg_match('/^(:?)([-=?+])(.*)$/s', $rest, $op) === 1) {
            $useWord = $value === null || ($op[1] === ':' && $value === '');
            $current = fn (): string => $list !== null ? $this->joinPositional($kind, $list) : $this->esc((string) $value);

            return match ($op[2]) {
                '-' => $useWord ? $this->expandOperandWord($op[3]) : $current(),
                '+' => $useWord ? '' : $this->expandOperandWord($op[3]),
                '=' => $useWord ? $this->esc($this->assignDefault($name, $subscript, $op[3])) : $current(),
                default => $useWord ? throw new ExpansionException(sprintf(
                    'bash: %s: %s',
                    $subscript === null ? $name : sprintf('%s[%s]', $name, $subscript),
                    $op[3] === '' ? 'parameter null or not set' : $this->stripMarkers($this->expandLiteralValue($op[3])),
                ), fatal: true) : $current(),
            };
        }

        if (preg_match('/^:([^:]*)(?::(.*))?$/s', $rest, $op) === 1) {
            $offset = $this->interpreter->evaluateArithmeticString($op[1]);
            $length = isset($op[2]) ? $this->interpreter->evaluateArithmeticString($op[2]) : null;

            if ($list === null) {
                $value ??= $this->boundValue($name, $subscript);

                return $offset < -$this->charLength($value) ? '' : $this->esc($this->utf8($value) ? mb_substr($value, $offset, $length, 'UTF-8') : substr($value, $offset, $length));
            }

            // ${@:0} starts at $0; a negative offset counts back from the last positional
            if ($subscript === null && $offset >= 0) {
                $list = [$this->interpreterState->getSpecialVar('0') ?? '', ...$list];
            }

            return $offset < -count($list) ? '' : $this->joinPositional($kind, array_slice($list, $offset, $length));
        }

        $transform = $this->valueTransform($rest, $content);

        // An array or "$@" is transformed element by element
        return $list !== null
            ? $this->joinPositional($kind, array_map($transform, $list))
            : $this->esc($transform($value ?? $this->boundValue($name, $subscript)));
    }

    /**
     * The operation of ${x#pat}, ${x/pat/rep} or ${x^^} as a function of the value; its words are expanded once, here.
     *
     * @return Closure(string): string
     */
    private function valueTransform(string $rest, string $content): Closure
    {
        $extglob = $this->interpreterState->shopt['extglob'];

        if (preg_match('/^(##|%%|#|%)(.*)$/s', $rest, $op) === 1) {
            $pattern = $this->expandPatternWord($op[2]);

            return fn (string $value): string => $this->removePattern($value, $pattern, $op[1], $extglob);
        }

        // The pattern ends at the first unescaped `/`, so `${x/\//_}` replaces a slash
        if (preg_match('#^/([/\#%]?)((?:\\\\.|[^/\\\\])*+)(?:/(.*))?$#s', $rest, $op) === 1) {
            $pattern = $this->expandPatternWord($op[2]);
            $replacement = $this->stripMarkers($this->expandLiteralValue($op[3] ?? ''));
            $nocase = $this->interpreterState->shopt['nocasematch'];

            return fn (string $value): string => $this->replacePattern($value, $pattern, $op[1], $replacement, $extglob, $nocase);
        }

        return match ($rest) {
            '^^' => mb_strtoupper(...),
            ',,' => mb_strtolower(...),
            '^' => fn (string $value): string => mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1),
            ',' => fn (string $value): string => mb_strtolower(mb_substr($value, 0, 1)).mb_substr($value, 1),
            default => throw new ExpansionException(sprintf('bash: ${%s}: bad substitution', $content), fatal: str_starts_with($rest, '@')),
        };
    }

    /** The word of ${x-word} or ${x+word}: inside double quotes its single quotes are literal, as in bash. */
    private function expandOperandWord(string $word): string
    {
        if (! $this->inDoubleQuotes) {
            return $this->expandLiteralValue($word);
        }

        $result = '';

        for ($i = 0; $i < strlen($word); $i++) {
            $result .= $this->expandQuotedText($word, $i, '"');
        }

        return $result;
    }

    /** Valid UTF-8 counts by character (the sandbox's locale is UTF-8); other text by byte. */
    private function utf8(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8');
    }

    private function charLength(string $value): int
    {
        return $this->utf8($value) ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /** ${name:=word}: assigns the expanded word (scalars and array elements only, as in bash). */
    private function assignDefault(string $name, ?string $subscript, string $word): string
    {
        if (preg_match('/^[a-zA-Z_]\w*$/', $name) !== 1) {
            throw new ExpansionException(sprintf('bash: $%s: cannot assign in this way', $name));
        }

        $expanded = $this->stripMarkers($this->expandLiteralValue($word));

        if ($subscript === null) {
            $this->interpreterState->setVar($name, $expanded);
        } else {
            $array = $this->interpreterState->getArray($name);
            $array[$this->arrayKey($name, $subscript)] = $expanded;
            $this->interpreterState->setArray($name, $array);
        }

        return $expanded;
    }

    /** A pattern operand of ${x#pat} / ${x/pat/rep}: expanded, with quoted characters escaped so they match literally. */
    private function expandPatternWord(string $word, bool $regex = false): string
    {
        return $this->markedToPattern($this->withMode(true, fn (): string => $this->expandLiteralValue($word)), $regex);
    }

    /** The value of $name / ${name[subscript]}, or null when unset. A bare array name means element 0. */
    private function lookup(string $name, ?string $subscript): ?string
    {
        if ($subscript !== null) {
            return $this->interpreterState->getArray($name)[$this->arrayKey($name, $subscript)] ?? null;
        }

        if (ctype_digit($name) && $name !== '0') {
            return $this->interpreterState->positionalParams[(int) $name - 1] ?? null;
        }

        return $this->interpreterState->getVar($name) ?? $this->interpreterState->getSpecialVar($name);
    }

    private function boundValue(string $name, ?string $subscript): string
    {
        $value = $this->lookup($name, $subscript);

        if ($value === null && ($this->interpreterState->shellOpts['nounset'] ?? false)) {
            throw new UnboundVariableException($subscript === null ? $name : sprintf('%s[%s]', $name, $subscript));
        }

        return $value ?? '';
    }

    /**
     * Associative arrays (any string key) are indexed by the expanded subscript, indexed arrays by its
     * arithmetic value; a negative index counts back from the end.
     */
    private function arrayKey(string $name, string $subscript): int|string
    {
        $array = $this->interpreterState->getArray($name);
        $key = $this->stripMarkers($this->expandLiteralValue($subscript));

        if (array_key_exists($key, $array) || array_filter(array_keys($array), is_string(...)) !== []) {
            return $key;
        }

        $index = $this->interpreter->fatalArithmetic($key);

        return $index < 0 && $array !== [] ? $index + (int) max(array_keys($array)) + 1 : $index;
    }

    /**
     * @return list<string>
     */
    private function expandBraceString(string $value): array
    {
        $brace = $this->findFirstExpandableBrace($value);

        if ($brace === null) {
            return [$value];
        }

        ['start' => $start, 'end' => $end, 'options' => $options] = $brace;
        $prefix = substr($value, 0, $start);
        $suffix = substr($value, $end + 1);
        $results = [];

        foreach ($options as $option) {
            foreach ($this->expandBraceString($prefix.$option.$suffix) as $expanded) {
                $results[] = $expanded;
                $this->limitBraceResults(count($results));
            }
        }

        return $results;
    }

    private function limitBraceResults(int $count): void
    {
        if ($count > $this->interpreterState->limits->maxBraceExpansionResults) {
            throw new ExecutionLimitException(sprintf('Brace expansion limit exceeded (%d words)', $this->interpreterState->limits->maxBraceExpansionResults));
        }
    }

    /** ${x#pat}, ${x##pat}, ${x%pat} and ${x%%pat}: the shortest or longest matching prefix or suffix goes. */
    private function removePattern(string $value, string $pattern, string $op, bool $extglob): string
    {
        $greedy = Glob::toRegex($pattern, $extglob);

        if ($greedy !== null) {
            $lazy = Glob::toRegex($pattern, $extglob, lazy: true);
            [$regex, $replacement] = match ($op) {
                '#' => ['/^'.$lazy.'/', ''],
                '##' => ['/^'.$greedy.'/', ''],
                '%' => ['/^(.*)'.$lazy.'\z/', '$1'],
                default => ['/^(.*?)'.$greedy.'\z/', '$1'],
            };

            return preg_replace($regex.Glob::flags($pattern.$value), $replacement, $value) ?? $value;
        }

        // `!(...)` has no regex: try each prefix or suffix length, shortest or longest first
        $len = strlen($value);
        $prefix = $op[0] === '#';

        foreach (strlen($op) === 1 ? range(0, $len) : range($len, 0) as $n) {
            if (Glob::matches($pattern, $prefix ? substr($value, 0, $n) : substr($value, $len - $n), $extglob)) {
                return $prefix ? substr($value, $n) : substr($value, 0, $len - $n);
            }
        }

        return $value;
    }

    /** ${x/pat/rep}, ${x//pat/rep}, ${x/#pat/rep} and ${x/%pat/rep}, with the longest match at each place. */
    private function replacePattern(string $value, string $pattern, string $anchor, string $replacement, bool $extglob, bool $nocase): string
    {
        $regex = Glob::toRegex($pattern, $extglob);

        if ($regex !== null) {
            // Unlike PCRE, bash finds no empty match at the very end of the value
            $regex = match ($anchor) {
                '#' => '^'.$regex,
                '%' => $regex.'\z',
                default => '(?!\z)'.$regex,
            };

            // The result's length is checked as it grows, so ${x//?/$x} can't exhaust memory
            $length = strlen($value);

            return preg_replace_callback('/'.$regex.'/'.Glob::flags($pattern.$value).($nocase ? 'i' : ''), function (array $match) use ($replacement, &$length): string {
                $length += strlen($replacement) - strlen($match[0]);
                $this->interpreterState->limitLength($length);

                return $replacement;
            }, $value, $anchor === '/' ? -1 : 1) ?? $value;
        }

        // `!(...)` has no regex: find each match by trying every span, leftmost first, then longest
        $result = '';
        $from = 0;

        while (($match = $this->findMatch($value, $pattern, $from, $anchor, $extglob, $nocase)) !== null) {
            [$start, $end] = $match;
            // An empty match replaces nothing, so the character after it is kept
            $result .= substr($value, $from, $start - $from).$replacement.($start === $end ? substr($value, $start, 1) : '');
            $from = max($end, $start + 1);

            if ($anchor !== '/') {
                break;
            }
        }

        return $result.substr($value, $from);
    }

    /** @return array{int, int}|null */
    private function findMatch(string $value, string $pattern, int $from, string $anchor, bool $extglob, bool $nocase): ?array
    {
        $len = strlen($value);

        for ($start = $from; $start <= ($anchor === '%' ? $len : $len - 1); $start++) {
            for ($end = $len; $end >= ($anchor === '%' ? $len : $start); $end--) {
                if (Glob::matches($pattern, substr($value, $start, $end - $start), $extglob, $nocase)) {
                    return [$start, $end];
                }
            }

            if ($anchor === '#') {
                break;
            }
        }

        return null;
    }

    /**
     * Pathname expansion, after the shopt glob options: dotglob, nocaseglob, globstar (`**`), extglob,
     * and nullglob or failglob for a pattern that matches nothing.
     *
     * @return list<string>
     */
    private function expandGlob(string $pattern): array
    {
        $this->globOperations = 0;
        $shopt = $this->interpreterState->shopt;
        $dirsOnly = str_ends_with($pattern, '/');
        $isAbsolute = str_starts_with($pattern, '/');
        $components = array_values(array_filter(explode('/', $pattern), fn (string $c): bool => $c !== ''));
        // Each candidate is [display path, filesystem path]
        $candidates = [[$isAbsolute ? '/' : '', $isAbsolute ? '/' : $this->interpreterState->cwd]];

        foreach ($components as $index => $component) {
            $isLast = $index === count($components) - 1;
            $next = [];

            foreach ($candidates as [$display, $real]) {
                $prefix = $display === '' || str_ends_with($display, '/') ? $display : $display.'/';
                $realPrefix = str_ends_with($real, '/') ? $real : $real.'/';
                $globstar = $component === '**' && $shopt['globstar'];

                if (! $globstar && ! Glob::isPattern($component, $shopt['extglob'])) {
                    $name = preg_replace('/\\\\(.)/s', '$1', $component) ?? $component;

                    // A directory is checked by the listing that follows; a last name must exist
                    if (! $isLast || in_array($name, $this->listing($real), true)) {
                        $next[] = [$prefix.$name, $realPrefix.$name];
                    }

                    continue;
                }

                // `**` is this directory and everything below it ("d/" itself when it's last)
                if ($globstar && (! $isLast || $prefix !== '')) {
                    $next[] = [$isLast ? $prefix : $display, $real];
                }

                foreach ($this->globEntries($real, $prefix, $realPrefix, $globstar ? '*' : $component, $globstar) as [$entryDisplay, $entryReal, $isDir]) {
                    if (($isLast && ! $dirsOnly) || $isDir) {
                        $next[] = [$entryDisplay, $entryReal];
                    }
                }
            }

            $candidates = $next;
        }

        if ($candidates === []) {
            $literal = preg_replace('/\\\\(.)/s', '$1', $pattern) ?? $pattern;

            if ($shopt['failglob']) {
                throw new ExpansionException('no match: '.$literal);
            }

            return $shopt['nullglob'] ? [] : [$literal];
        }

        $paths = array_map(fn (array $c): string => $dirsOnly ? rtrim($c[0], '/').'/' : $c[0], $candidates);
        sort($paths);

        return $paths;
    }

    /** @return list<string> a directory's entries, none when it can't be listed */
    private function listing(string $dir): array
    {
        try {
            $entries = $this->interpreter->listDirectory($dir);
        } catch (RuntimeException) {
            return [];
        }

        $this->globOperations += count($entries) + 1;

        if ($this->globOperations > $this->interpreterState->limits->maxGlobOperations) {
            throw new ExecutionLimitException(sprintf('Glob operation limit exceeded (%d directory entries)', $this->interpreterState->limits->maxGlobOperations));
        }

        return $entries;
    }

    /**
     * Entries of $real matching $component as [display, real, is a directory]; $recursive walks into
     * subdirectories (but not symlinks to them), for `**`.
     *
     * @return list<array{string, string, bool}>
     */
    private function globEntries(string $real, string $prefix, string $realPrefix, string $component, bool $recursive): array
    {
        $shopt = $this->interpreterState->shopt;
        $entries = $this->listing($real);
        sort($entries);
        $found = [];

        foreach ($entries as $entry) {
            // Dotfiles only match a pattern that starts with a literal dot, unless dotglob
            if ($entry[0] === '.' && ! $shopt['dotglob'] && ! str_starts_with($component, '.')) {
                continue;
            }

            if (! Glob::matches($component, $entry, $shopt['extglob'], $shopt['nocaseglob'])) {
                continue;
            }

            $isDir = $this->interpreter->isDirectory($realPrefix.$entry, followLinks: ! $recursive);
            $found[] = [$prefix.$entry, $realPrefix.$entry, $isDir];

            if ($recursive && $isDir) {
                array_push($found, ...$this->globEntries($realPrefix.$entry, $prefix.$entry.'/', $realPrefix.$entry.'/', $component, true));
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function getOrderedArrayValues(string $arrayName): array
    {
        return array_values($this->getOrderedArrayEntries($arrayName));
    }

    /**
     * @return list<int|string>
     */
    private function getOrderedArrayKeys(string $arrayName): array
    {
        return array_keys($this->getOrderedArrayEntries($arrayName));
    }

    /**
     * @return array<int|string, string>
     */
    private function getOrderedArrayEntries(string $arrayName): array
    {
        $array = $this->interpreterState->getArray($arrayName);
        $keys = array_keys($array);
        $numeric = array_reduce(
            $keys,
            fn (bool $carry, int|string $key): bool => $carry && is_int($key),
            true,
        );

        if ($numeric) {
            ksort($array);
        }

        return $array;
    }

    /**
     * @return array{start: int, end: int, options: list<string>}|null
     */
    private function findFirstExpandableBrace(string $value): ?array
    {
        $len = strlen($value);
        $quote = null;

        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];

            if ($char === '\\') {
                $i++;

                continue;
            }

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;

                continue;
            }

            if ($char !== '{') {
                continue;
            }

            if ($i > 0 && $value[$i - 1] === '$') {
                continue;
            }

            $depth = 1;

            for ($j = $i + 1; $j < $len; $j++) {
                if ($value[$j] === '\\') {
                    $j++;

                    continue;
                }

                if ($value[$j] === '{') {
                    $depth++;
                } elseif ($value[$j] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $body = substr($value, $i + 1, $j - $i - 1);
                        $options = $this->parseBraceBody($body);

                        if ($options !== null) {
                            return ['start' => $i, 'end' => $j, 'options' => $options];
                        }

                        break;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function parseBraceBody(string $body): ?array
    {
        $parts = $this->splitTopLevelBraceItems($body);

        if (count($parts) > 1) {
            return $parts;
        }

        if (preg_match('/^(-?\d+|[a-zA-Z])\.\.(-?\d+|[a-zA-Z])(?:\.\.(-?\d+))?$/', $body, $matches) === 1 && is_numeric($matches[1]) === is_numeric($matches[2])) {
            $step = max(1, abs((int) ($matches[3] ?? 1)));
            $bounds = is_numeric($matches[1]) ? [(int) $matches[1], (int) $matches[2]] : [$matches[1], $matches[2]];
            // Counted before range() builds it, so {1..100000000} never takes the memory
            $this->limitBraceResults(intdiv(abs((int) $bounds[1] - (int) $bounds[0]), $step) + 1);

            // A bound written with a leading zero pads every number to the wider bound's width: {05..10}
            $width = is_numeric($matches[1]) && preg_match('/^-?0\d/m', $matches[1]."\n".$matches[2]) === 1 ? max(strlen($matches[1]), strlen($matches[2])) : 0;

            return array_map(fn (int|string $n): string => $width > 0 ? sprintf('%0'.$width.'d', $n) : (string) $n, range($bounds[0], $bounds[1], $step));
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function splitTopLevelBraceItems(string $body): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $len = strlen($body);

        for ($i = 0; $i < $len; $i++) {
            $char = $body[$i];

            if ($char === '\\' && $i + 1 < $len) {
                $current .= $char.$body[$i + 1];
                $i++;

                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }
}
