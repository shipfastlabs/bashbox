<?php

declare(strict_types=1);

namespace BashBox\Parser;

use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Exceptions\ParseException;
use BashBox\Limits;

final class Lexer
{
    /** What closes each quote, substitution or group, keyed by the text that opens it. */
    private const array CLOSERS = [
        "'" => "'", '"' => '"', '`' => '`', "$'" => "'", '${' => '}', '$(' => ')',
        // Process substitutions and extglob groups.
        '<(' => ')', '>(' => ')', '?(' => ')', '*(' => ')', '+(' => ')', '@(' => ')', '!(' => ')',
    ];

    /** @var list<Token> */
    private array $tokens = [];

    /**
     * Heredocs awaiting their bodies: delimiter token index, delimiter, whether `<<-` strips tabs, whether quoted.
     *
     * @var list<array{int, string, bool, bool}>
     */
    private array $pendingHeredocs = [];

    private int $dparenDepth = 0;

    private bool $done = false;

    private const array RESERVED_WORDS = [
        'if' => TokenType::IF,
        'then' => TokenType::THEN,
        'else' => TokenType::ELSE,
        'elif' => TokenType::ELIF,
        'fi' => TokenType::FI,
        'for' => TokenType::FOR,
        'while' => TokenType::WHILE,
        'until' => TokenType::UNTIL,
        'do' => TokenType::DO,
        'done' => TokenType::DONE,
        'case' => TokenType::CASE,
        'esac' => TokenType::ESAC,
        'in' => TokenType::IN,
        'function' => TokenType::FUNCTION,
        'select' => TokenType::SELECT,
        'time' => TokenType::TIME,
        'coproc' => TokenType::COPROC,
    ];

    private const array SINGLE_CHAR_OPS = [
        '|' => TokenType::PIPE,
        '&' => TokenType::AMP,
        ';' => TokenType::SEMICOLON,
        '(' => TokenType::LPAREN,
        ')' => TokenType::RPAREN,
        '<' => TokenType::LESS,
        '>' => TokenType::GREAT,
    ];

    /** $pos is where lexing starts and $depth how deeply that is nested in substitutions. */
    public function __construct(private readonly string $input, private int $line = 1, private readonly Limits $limits = new Limits, private int $pos = 0, private readonly int $depth = 0)
    {
        if (strlen($input) > $limits->maxInputSize) {
            throw new ExecutionLimitException(sprintf('Input size limit exceeded (%d bytes)', $limits->maxInputSize));
        }
    }

    /**
     * The tokens, ending in NEWLINE and EOF as bash -c sees its script; a heredoc body follows its delimiter.
     *
     * @return list<Token>
     */
    public function tokenize(): array
    {
        while ($this->lexNext()) {
        }

        return $this->tokens;
    }

    /** Token $index, lexing only as far as needed (past the end it's EOF); a line's heredoc bodies are read before its tokens are handed out. */
    public function token(int $index): Token
    {
        while ((! isset($this->tokens[$index]) || $this->pendingHeredocs !== []) && $this->lexNext()) {
        }

        return $this->tokens[$index] ?? $this->tokens[count($this->tokens) - 1];
    }

    /** Lexes one more token (or the heredoc bodies due); false once EOF is in. */
    private function lexNext(): bool
    {
        if ($this->done) {
            return false;
        }

        $previous = end($this->tokens) ?: null;

        if ($this->pendingHeredocs !== [] && $previous?->type === TokenType::NEWLINE) {
            $this->readHeredocBodies();

            return true;
        }

        $this->skipWhitespace();
        $len = strlen($this->input);

        if ($this->pos >= $len) {
            if ($this->dparenDepth > 0) {
                throw new ParseException("unexpected EOF while looking for matching `)'");
            }

            // A heredoc on the last line has an empty body.
            $this->readHeredocBodies();
            $this->tokens[] = new Token(TokenType::NEWLINE, "\n", $len, $len, $this->line);
            $this->tokens[] = new Token(TokenType::EOF, '', $len, $len, $this->line);
            $this->done = true;

            return true;
        }

        $token = $this->nextToken();

        // The word after << is a heredoc delimiter, except inside (( )) where << shifts.
        if (
            in_array($previous?->type, [TokenType::DLESS, TokenType::DLESSDASH], true)
            && $this->dparenDepth === 0
            && $token->type !== TokenType::COMMENT
            && ! $this->isWordBoundary($this->input[$token->start])
        ) {
            $token = $this->heredocDelimiter($token, $previous->type === TokenType::DLESSDASH);
        }

        $this->tokens[] = $token;

        if (count($this->tokens) > $this->limits->maxTokens) {
            throw new ExecutionLimitException(sprintf('Token limit exceeded (%d tokens)', $this->limits->maxTokens));
        }

        return true;
    }

    private function skipWhitespace(): void
    {
        $len = strlen($this->input);

        while ($this->pos < $len) {
            $char = $this->input[$this->pos];

            if ($char === ' ' || $char === "\t") {
                $this->pos++;
            } elseif ($char === '\\' && ($this->input[$this->pos + 1] ?? '') === "\n") {
                $this->pos += 2;
                $this->line++;
            } else {
                break;
            }
        }
    }

    private function nextToken(): Token
    {
        $pos = $this->pos;
        $startLine = $this->line;
        $c0 = $this->input[$pos];
        $c1 = $this->input[$pos + 1] ?? '';
        $c2 = $this->input[$pos + 2] ?? '';

        if ($c0 === '#' && $this->dparenDepth === 0) {
            return $this->readComment($pos, $startLine);
        }

        if ($c0 === "\n") {
            $this->pos = $pos + 1;
            $this->line++;

            return new Token(TokenType::NEWLINE, "\n", $pos, $pos + 1, $startLine);
        }

        if (($c0 === '<' || $c0 === '>') && $c1 === '(' && $this->dparenDepth === 0) {
            return $this->readWord($pos, $startLine);
        }

        $three = [
            '<<-' => TokenType::DLESSDASH,
            '<<<' => TokenType::TLESS,
            '&>>' => TokenType::AND_DGREAT,
            ';;&' => TokenType::SEMI_SEMI_AND,
        ][$c0.$c1.$c2] ?? null;

        if ($three !== null && ($three !== TokenType::SEMI_SEMI_AND || $this->dparenDepth === 0)) {
            return $this->operator($three, $pos, 3);
        }

        if ($c0 === '(' && $c1 === '(' && $this->dparenDepth === 0) {
            $this->dparenDepth = 1;

            return $this->operator(TokenType::DPAREN_START, $pos, 2);
        }

        if ($c0 === ')' && $c1 === ')' && $this->dparenDepth === 1) {
            $this->dparenDepth = 0;

            return $this->operator(TokenType::DPAREN_END, $pos, 2);
        }

        $twoCharOps = [
            ['<', '<', TokenType::DLESS],
            ['[', '[', TokenType::DBRACK_START],
            [']', ']', TokenType::DBRACK_END],
            ['&', '&', TokenType::AND_AND],
            ['|', '|', TokenType::OR_OR],
            [';', ';', TokenType::DSEMI],
            [';', '&', TokenType::SEMI_AND],
            ['|', '&', TokenType::PIPE_AMP],
            ['>', '>', TokenType::DGREAT],
            ['<', '&', TokenType::LESSAND],
            ['>', '&', TokenType::GREATAND],
            ['<', '>', TokenType::LESSGREAT],
            ['>', '|', TokenType::CLOBBER],
            ['&', '>', TokenType::AND_GREAT],
        ];

        foreach ($twoCharOps as [$first, $second, $type]) {
            if ($c0 === $first && $c1 === $second) {
                if (($type === TokenType::DBRACK_START || $type === TokenType::DBRACK_END) && $c2 !== '' && ! $this->isWordBoundary($c2)) {
                    break;
                }

                if ($this->dparenDepth > 0 && ($type === TokenType::DSEMI || $type === TokenType::SEMI_AND)) {
                    continue;
                }

                return $this->operator($type, $pos, 2);
            }
        }

        if ($c0 === '(' && $this->dparenDepth > 0) {
            $this->dparenDepth++;
        }

        if ($c0 === ')' && $this->dparenDepth > 1) {
            $this->dparenDepth--;
        }

        if (isset(self::SINGLE_CHAR_OPS[$c0])) {
            return $this->operator(self::SINGLE_CHAR_OPS[$c0], $pos, 1);
        }

        // In {name}>file the variable receives the new fd's number.
        if ($c0 === '{' && $this->dparenDepth === 0 && preg_match('/\G\{([a-zA-Z_]\w*(?:\[[^\]]*\])?)\}(?=[<>](?!\())/', $this->input, $m, 0, $pos) === 1) {
            $this->pos = $pos + strlen($m[0]);

            return new Token(TokenType::FD_VARIABLE, $m[1], $pos, $this->pos, $startLine);
        }

        if ($c0 === '{') {
            if ($c1 === '}') {
                $this->pos = $pos + 2;

                return new Token(TokenType::WORD, '{}', $pos, $pos + 2, $startLine);
            }

            if (in_array($c1, ['', ' ', "\t", "\n", ';'], true)) {
                return $this->operator(TokenType::LBRACE, $pos, 1);
            }

            return $this->readWord($pos, $startLine);
        }

        if ($c0 === '}') {
            return $this->operator(TokenType::RBRACE, $pos, 1);
        }

        if ($c0 === '!') {
            // `!(...)` negates a subshell in command position; after a word it's an extglob pattern.
            $afterWord = in_array(($this->tokens[count($this->tokens) - 1] ?? null)?->type, [TokenType::WORD, TokenType::NAME, TokenType::NUMBER, TokenType::ASSIGNMENT_WORD, TokenType::IN], true);

            if (in_array($c1, ['', ' ', "\t", "\n"], true) || ($c1 === '(' && ! $afterWord)) {
                return $this->operator(TokenType::BANG, $pos, 1);
            }
        }

        return $this->readWord($pos, $startLine);
    }

    private function operator(TokenType $tokenType, int $pos, int $length): Token
    {
        $this->pos = $pos + $length;

        return new Token($tokenType, substr($this->input, $pos, $length), $pos, $this->pos, $this->line);
    }

    private function readComment(int $pos, int $startLine): Token
    {
        $end = strpos($this->input, "\n", $pos);
        $this->pos = $end === false ? strlen($this->input) : $end;

        return new Token(TokenType::COMMENT, substr($this->input, $pos, $this->pos - $pos), $pos, $this->pos, $startLine);
    }

    private function readWord(int $pos, int $startLine): Token
    {
        $start = $pos;
        $value = '';
        $len = strlen($this->input);

        while ($pos < $len) {
            $ch = $this->input[$pos];
            $opener = $this->opener($pos);

            if ($opener !== null || ($ch === '[' && $this->assignmentAcceptable() && preg_match('/^[a-zA-Z_]\w*$/', $value) === 1)) {
                // Where an assignment can start, `name[...]` takes a whole subscript, blanks included
                $end = $opener === null ? $this->subscriptEnd($pos) : $this->skipQuoted($pos, $opener);
                $value .= substr($this->input, $pos, $end - $pos);
                $pos = $end;
            } elseif ($this->isWordBoundary($ch)) {
                break;
            } elseif ($ch === '\\') {
                // A backslash-newline joins lines; any other backslash keeps the character after it.
                $value .= ($this->input[$pos + 1] ?? '') === "\n" ? '' : substr($this->input, $pos, 2);
                $pos = min($pos + 2, $len);
            } else {
                $value .= $ch;
                $pos++;
            }
        }

        $this->pos = $pos;
        // Quotes, substitutions and continuations may span lines.
        $this->line += substr_count($this->input, "\n", $start, $pos - $start);

        return new Token($this->classifyWord($value), $value, $start, $pos, $startLine);
    }

    /** Whether the next word is in command position, where bash takes `name[...]=` as an assignment. */
    private function assignmentAcceptable(): bool
    {
        return $this->dparenDepth === 0 && in_array((end($this->tokens) ?: null)?->type, [
            null, TokenType::NEWLINE, TokenType::SEMICOLON, TokenType::AMP, TokenType::PIPE, TokenType::PIPE_AMP,
            TokenType::AND_AND, TokenType::OR_OR, TokenType::LPAREN, TokenType::RPAREN, TokenType::LBRACE, TokenType::BANG,
            TokenType::DO, TokenType::ELSE, TokenType::ELIF, TokenType::IF, TokenType::THEN, TokenType::WHILE,
            TokenType::UNTIL, TokenType::TIME, TokenType::ASSIGNMENT_WORD,
        ], true);
    }

    /** Just past the `]` matching the `[` at $pos, skipping quotes and nested brackets. */
    private function subscriptEnd(int $pos): int
    {
        $level = 0;

        for ($len = strlen($this->input); $pos < $len; $pos++) {
            $opener = $this->opener($pos);
            $ch = $this->input[$pos];

            if ($opener !== null) {
                $pos = $this->skipQuoted($pos, $opener) - 1;
            } elseif ($ch === '\\') {
                $pos++;
            } elseif ($ch === '[') {
                $level++;
            } elseif ($ch === ']' && --$level === 0) {
                return $pos + 1;
            }
        }

        throw new ParseException("unexpected EOF while looking for matching `]'");
    }

    /** The quote, substitution or group that opens at $pos, as a key of CLOSERS. */
    private function opener(int $pos): ?string
    {
        $two = substr($this->input, $pos, 2);

        if (isset(self::CLOSERS[$two]) && ($two[0] === '$' || $this->dparenDepth === 0)) {
            return $two;
        }

        return isset(self::CLOSERS[$this->input[$pos]]) ? $this->input[$pos] : null;
    }

    /** The position just past the quote or substitution $open (`$(`, `${`, `<(`...) starts at $pos in $text. */
    public static function skipPast(string $text, int $pos, string $open, Limits $limits = new Limits): int
    {
        return new self($text, 1, $limits)->skipQuoted($pos, $open);
    }

    /**
     * The position just past what $open opens at $pos; inside "..." only `...`, $(...) and ${...} nest.
     * A command substitution ends where its command list does, as bash parses it; with $countParens (or a
     * list that doesn't parse, which is reported when it runs) parentheses are counted instead.
     */
    private function skipQuoted(int $pos, string $open, ?int $depth = null, bool $countParens = false): int
    {
        $depth ??= $this->depth;

        if ($depth > $this->limits->maxAstDepth) {
            throw new ExecutionLimitException(sprintf('Nesting depth limit exceeded (%d)', $this->limits->maxAstDepth));
        }

        if (! $countParens && in_array($open, ['$(', '<(', '>('], true) && ($open !== '$(' || ($this->input[$pos + 2] ?? '') !== '(')) {
            try {
                return Parser::substitutionEnd($this->input, $pos + 2, $this->limits, $depth + 1);
            } catch (ParseException) {
                $countParens = true;
            }
        }

        $close = self::CLOSERS[$open];
        $nest = $open[1] ?? '';
        $len = strlen($this->input);
        $level = 0;

        for ($i = $pos + strlen($open); $i < $len; $i++) {
            $ch = $this->input[$i];

            if ($ch === $close) {
                if ($level-- === 0) {
                    return $i + 1;
                }
            } elseif ($open === "'") {
                continue;
            } elseif ($ch === '\\') {
                $i++;
            } elseif ($open !== "$'" && $open !== '`') {
                $inner = $this->opener($i);

                if ($inner !== null && ($open !== '"' || in_array($inner, ['`', '$(', '${'], true))) {
                    $i = $this->skipQuoted($i, $inner, $depth + 1, $countParens) - 1;
                } elseif ($ch === $nest) {
                    $level++;
                }
            }
        }

        throw new ParseException(sprintf("unexpected EOF while looking for matching `%s'", $close));
    }

    /**
     * The $'...' starting at $start (on the `$`): its text with the escapes decoded, and the position after it.
     *
     * @return array{string, int}
     */
    public static function ansiCQuoted(string $text, int $start): array
    {
        $len = strlen($text);

        for ($end = $start + 2; $end < $len && $text[$end] !== "'"; $end++) {
            $end += $text[$end] === '\\' ? 1 : 0;
        }

        return [self::ansiC(substr($text, $start + 2, $end - $start - 2)), min($end + 1, $len)];
    }

    /** bash's $'...' escapes; the text ends at a NUL, as a C string would */
    public static function ansiC(string $text): string
    {
        $decoded = (string) preg_replace_callback(
            '/\\\\(?:([abeEfnrtv\\\\\'"?])|([0-7]{1,3})|x([0-9a-fA-F]{1,2})|u([0-9a-fA-F]{1,4})|U([0-9a-fA-F]{1,8})|c(.))/s',
            fn (array $m): string => match (true) {
                $m[1] !== null => strtr($m[1], ['a' => "\x07", 'b' => "\x08", 'e' => "\e", 'E' => "\e", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v"]),
                $m[2] !== null => chr(octdec($m[2]) & 0xFF),
                $m[3] !== null => chr((int) hexdec($m[3])),
                $m[4] !== null || $m[5] !== null => mb_chr((int) hexdec((string) ($m[4] ?? $m[5])), 'UTF-8'),
                default => $m[6] === '?' ? "\x7F" : chr(ord(strtoupper((string) $m[6])) & 0x1F), // \cX
            },
            $text,
            flags: PREG_UNMATCHED_AS_NULL,
        );
        $nul = strpos($decoded, "\0");

        return $nul === false ? $decoded : substr($decoded, 0, $nul);
    }

    /** A quoted word is never a number, reserved word or name, since those can't hold quotes. */
    private function classifyWord(string $value): TokenType
    {
        return match (true) {
            $this->looksLikeAssignment($value) => TokenType::ASSIGNMENT_WORD,
            ctype_digit($value) => TokenType::NUMBER,
            isset(self::RESERVED_WORDS[$value]) => self::RESERVED_WORDS[$value],
            preg_match('/^[a-zA-Z_]\w*$/', $value) === 1 => TokenType::NAME,
            default => TokenType::WORD,
        };
    }

    private function looksLikeAssignment(string $value): bool
    {
        $eqPos = $this->findAssignmentEquals($value);

        if ($eqPos === -1) {
            return false;
        }

        $lhs = substr($value, 0, $eqPos);

        // Handle += by stripping trailing +
        if (str_ends_with($lhs, '+')) {
            $lhs = substr($lhs, 0, -1);
        }

        return $this->isValidAssignmentLHS($lhs);
    }

    private function findAssignmentEquals(string $str): int
    {
        $depth = 0;
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $c = $str[$i];

            if ($c === '[') {
                $depth++;
            } elseif ($c === ']') {
                $depth--;
            } elseif ($depth === 0 && $c === '=') {
                return $i;
            } elseif ($depth === 0 && $c === '+' && ($i + 1 < $len) && $str[$i + 1] === '=') {
                return $i + 1;
            }
        }

        return -1;
    }

    private function isValidAssignmentLHS(string $str): bool
    {
        if (! preg_match('/^[a-zA-Z_]\w*/', $str, $matches)) {
            return false;
        }

        $afterName = substr($str, strlen($matches[0]));

        if ($afterName === '') {
            return true;
        }

        if ($afterName[0] === '[') {
            $depth = 0;
            $len = strlen($afterName);
            $i = 0;

            for (; $i < $len; $i++) {
                if ($afterName[$i] === '[') {
                    $depth++;
                } elseif ($afterName[$i] === ']') {
                    $depth--;

                    if ($depth === 0) {
                        break;
                    }
                }
            }

            // $afterName is bracket-balanced (see findAssignmentEquals), so the loop always breaks
            return $i === $len - 1;
        }

        return false;
    }

    /** The delimiter after << without its quotes, so E"OF" ends at EOF; any quoting leaves the body unexpanded. */
    private function heredocDelimiter(Token $token, bool $stripTabs): Token
    {
        $delimiter = (string) preg_replace_callback(
            '/\'([^\']*)\'|"((?:\\\\.|[^"])*)"|\\\\(.)/s',
            fn (array $m): string => $m[1].preg_replace('/\\\\([$`"\\\\\n])/', '$1', $m[2] ?? '').($m[3] ?? ''),
            $token->value,
        );
        $this->pendingHeredocs[] = [count($this->tokens), $delimiter, $stripTabs, $delimiter !== $token->value];

        return new Token(TokenType::WORD, $delimiter, $token->start, $token->end, $token->line);
    }

    /** Read each pending heredoc body into a HEREDOC_CONTENT token right after its delimiter. */
    private function readHeredocBodies(): void
    {
        $len = strlen($this->input);

        foreach ($this->pendingHeredocs as $k => [$index, $delimiter, $stripTabs, $quoted]) {
            $body = '';

            while ($this->pos < $len) {
                $end = strpos($this->input, "\n", $this->pos);
                $line = substr($this->input, $this->pos, ($end === false ? $len : $end) - $this->pos);
                $this->pos = $end === false ? $len : $end + 1;
                $this->line++;

                if (($stripTabs ? ltrim($line, "\t") : $line) === $delimiter) {
                    break;
                }

                $body .= $line."\n";
            }

            if (strlen($body) > $this->limits->maxHereDocSize) {
                throw new ExecutionLimitException(sprintf('Here-document size limit exceeded (%d bytes)', $this->limits->maxHereDocSize));
            }

            $delimiterToken = $this->tokens[$index + $k];
            array_splice($this->tokens, $index + $k + 1, 0, [new Token(TokenType::HEREDOC_CONTENT, $body, $delimiterToken->end, $delimiterToken->end, $delimiterToken->line, $quoted)]);
        }

        $this->pendingHeredocs = [];
    }

    private function isWordBoundary(string $char): bool
    {
        return in_array($char, [' ', "\t", "\n", ';', '&', '|', '(', ')', '<', '>'], true);
    }
}
