<?php

declare(strict_types=1);

namespace BashBox\Parser;

use BashBox\Exceptions\ParseException;

final class Lexer
{
    private string $input;

    private int $pos = 0;

    private int $line = 1;

    private int $column = 1;

    /** @var list<Token> */
    private array $tokens = [];

    /** @var list<array{delimiter: string, stripTabs: bool, quoted: bool}> */
    private array $pendingHeredocs = [];

    private int $dparenDepth = 0;

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

    public function __construct(string $input, private readonly int $maxHeredocSize = ParserLimits::MAX_HEREDOC_SIZE)
    {
        if (strlen($input) > ParserLimits::MAX_INPUT_SIZE) {
            throw new ParseException('Input exceeds maximum size of '.ParserLimits::MAX_INPUT_SIZE.' bytes');
        }

        $this->input = $input;
    }

    /**
     * @return list<Token>
     */
    public function tokenize(): array
    {
        $len = strlen($this->input);

        while ($this->pos < $len) {
            if (
                $this->pendingHeredocs !== [] &&
                $this->tokens !== [] &&
                $this->tokens[count($this->tokens) - 1]->type === TokenType::NEWLINE
            ) {
                $this->readHeredocContent();

                continue;
            }

            $this->skipWhitespace();

            if ($this->pos >= $len) {
                break;
            }

            $token = $this->nextToken();
            $this->tokens[] = $token;

            if (count($this->tokens) > ParserLimits::MAX_TOKENS) {
                throw new ParseException('Token limit exceeded');
            }
        }

        $this->tokens[] = new Token(
            type: TokenType::EOF,
            value: '',
            start: $this->pos,
            end: $this->pos,
            line: $this->line,
            column: $this->column,
        );

        return $this->tokens;
    }

    private function skipWhitespace(): void
    {
        $len = strlen($this->input);

        while ($this->pos < $len) {
            $char = $this->input[$this->pos];

            if ($char === ' ' || $char === "\t") {
                $this->pos++;
                $this->column++;
            } elseif ($char === '\\' && ($this->pos + 1 < $len) && $this->input[$this->pos + 1] === "\n") {
                $this->pos += 2;
                $this->line++;
                $this->column = 1;
            } else {
                break;
            }
        }
    }

    private function nextToken(): Token
    {
        $pos = $this->pos;
        $startLine = $this->line;
        $startColumn = $this->column;
        $c0 = $this->input[$pos] ?? '';
        $c1 = $this->input[$pos + 1] ?? '';
        $c2 = $this->input[$pos + 2] ?? '';

        // Comments
        if ($c0 === '#' && $this->dparenDepth === 0) {
            return $this->readComment($pos, $startLine, $startColumn);
        }

        // Newline
        if ($c0 === "\n") {
            $this->pos = $pos + 1;
            $this->line++;
            $this->column = 1;

            return new Token(TokenType::NEWLINE, "\n", $pos, $pos + 1, $startLine, $startColumn);
        }

        // Three-character operators
        if ($c0 === '<' && $c1 === '<' && $c2 === '-') {
            $this->pos = $pos + 3;
            $this->column = $startColumn + 3;
            $this->registerHeredocFromLookahead(true);

            return new Token(TokenType::DLESSDASH, '<<-', $pos, $pos + 3, $startLine, $startColumn);
        }

        if ($c0 === '<' && $c1 === '<' && $c2 === '<') {
            $this->pos = $pos + 3;
            $this->column = $startColumn + 3;

            return new Token(TokenType::TLESS, '<<<', $pos, $pos + 3, $startLine, $startColumn);
        }

        if ($c0 === '&' && $c1 === '>' && $c2 === '>') {
            $this->pos = $pos + 3;
            $this->column = $startColumn + 3;

            return new Token(TokenType::AND_DGREAT, '&>>', $pos, $pos + 3, $startLine, $startColumn);
        }

        if ($c0 === ';' && $c1 === ';' && $c2 === '&') {
            $this->pos = $pos + 3;
            $this->column = $startColumn + 3;

            return new Token(TokenType::SEMI_SEMI_AND, ';;&', $pos, $pos + 3, $startLine, $startColumn);
        }

        // Two-character operators
        if ($c0 === '<' && $c1 === '<') {
            $this->pos = $pos + 2;
            $this->column = $startColumn + 2;
            $this->registerHeredocFromLookahead(false);

            return new Token(TokenType::DLESS, '<<', $pos, $pos + 2, $startLine, $startColumn);
        }

        if ($c0 === '(' && $c1 === '(') {
            if ($this->dparenDepth > 0) {
                $this->pos = $pos + 1;
                $this->column = $startColumn + 1;
                $this->dparenDepth++;

                return new Token(TokenType::LPAREN, '(', $pos, $pos + 1, $startLine, $startColumn);
            }

            $this->pos = $pos + 2;
            $this->column = $startColumn + 2;
            $this->dparenDepth = 1;

            return new Token(TokenType::DPAREN_START, '((', $pos, $pos + 2, $startLine, $startColumn);
        }

        if ($c0 === ')' && $c1 === ')') {
            if ($this->dparenDepth === 1) {
                $this->pos = $pos + 2;
                $this->column = $startColumn + 2;
                $this->dparenDepth = 0;

                return new Token(TokenType::DPAREN_END, '))', $pos, $pos + 2, $startLine, $startColumn);
            }

            if ($this->dparenDepth > 1) {
                $this->pos = $pos + 1;
                $this->column = $startColumn + 1;
                $this->dparenDepth--;

                return new Token(TokenType::RPAREN, ')', $pos, $pos + 1, $startLine, $startColumn);
            }

            $this->pos = $pos + 1;
            $this->column = $startColumn + 1;

            return new Token(TokenType::RPAREN, ')', $pos, $pos + 1, $startLine, $startColumn);
        }

        // Two-char ops table
        $twoCharOps = [
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
                if ($type === TokenType::DBRACK_START || $type === TokenType::DBRACK_END) {
                    $afterOp = $this->input[$pos + 2] ?? '';
                    if ($afterOp !== '' && ! $this->isWordBoundary($afterOp)) {
                        break;
                    }
                }

                if (
                    $this->dparenDepth > 0
                    && $first === ';'
                    && in_array($type, [TokenType::DSEMI, TokenType::SEMI_AND, TokenType::SEMI_SEMI_AND], true)
                ) {
                    continue;
                }

                $this->pos = $pos + 2;
                $this->column = $startColumn + 2;

                return new Token($type, $first.$second, $pos, $pos + 2, $startLine, $startColumn);
            }
        }

        // Track parens in arithmetic context
        if ($c0 === '(' && $this->dparenDepth > 0) {
            $this->pos = $pos + 1;
            $this->column = $startColumn + 1;
            $this->dparenDepth++;

            return new Token(TokenType::LPAREN, '(', $pos, $pos + 1, $startLine, $startColumn);
        }

        if ($c0 === ')' && $this->dparenDepth > 1) {
            $this->pos = $pos + 1;
            $this->column = $startColumn + 1;
            $this->dparenDepth--;

            return new Token(TokenType::RPAREN, ')', $pos, $pos + 1, $startLine, $startColumn);
        }

        // Single-character operators
        if (isset(self::SINGLE_CHAR_OPS[$c0])) {
            $this->pos = $pos + 1;
            $this->column = $startColumn + 1;

            return new Token(self::SINGLE_CHAR_OPS[$c0], $c0, $pos, $pos + 1, $startLine, $startColumn);
        }

        // { and } handling
        if ($c0 === '{') {
            if ($c1 === '}') {
                $this->pos = $pos + 2;
                $this->column = $startColumn + 2;

                return new Token(TokenType::WORD, '{}', $pos, $pos + 2, $startLine, $startColumn);
            }

            $next = $this->input[$pos + 1] ?? '';
            if (in_array($next, ['', ' ', "\t", "\n", ';'], true)) {
                $this->pos = $pos + 1;
                $this->column = $startColumn + 1;

                return new Token(TokenType::LBRACE, '{', $pos, $pos + 1, $startLine, $startColumn);
            }

            return $this->readWord($pos, $startLine, $startColumn);
        }

        if ($c0 === '}') {
            $this->pos = $pos + 1;
            $this->column = $startColumn + 1;

            return new Token(TokenType::RBRACE, '}', $pos, $pos + 1, $startLine, $startColumn);
        }

        if ($c0 === '!') {
            $next = $this->input[$pos + 1] ?? '';
            if (in_array($next, ['', ' ', "\t", "\n"], true)) {
                $this->pos = $pos + 1;
                $this->column = $startColumn + 1;

                return new Token(TokenType::BANG, '!', $pos, $pos + 1, $startLine, $startColumn);
            }

            return $this->readWord($pos, $startLine, $startColumn);
        }

        // Word (everything else)
        return $this->readWord($pos, $startLine, $startColumn);
    }

    private function readComment(int $pos, int $startLine, int $startColumn): Token
    {
        $start = $pos;
        $len = strlen($this->input);

        while ($pos < $len && $this->input[$pos] !== "\n") {
            $pos++;
        }

        $value = substr($this->input, $start, $pos - $start);
        $this->pos = $pos;
        $this->column = $startColumn + ($pos - $start);

        return new Token(TokenType::COMMENT, $value, $start, $pos, $startLine, $startColumn);
    }

    private function readWord(int $pos, int $startLine, int $startColumn): Token
    {
        $start = $pos;
        $value = '';
        $wasQuoted = false;
        $singleQuoted = false;
        $len = strlen($this->input);

        while ($pos < $len) {
            $ch = $this->input[$pos];

            if ($this->isWordBoundary($ch)) {
                break;
            }

            if ($ch === '\\') {
                if ($pos + 1 < $len) {
                    if ($this->input[$pos + 1] === "\n") {
                        // Line continuation
                        $pos += 2;
                        $this->line++;
                        $this->column = 1;

                        continue;
                    }

                    $value .= $ch.$this->input[$pos + 1];
                    $pos += 2;
                    $wasQuoted = true;

                    continue;
                }

                $value .= $ch;
                $pos++;

                continue;
            }

            if ($ch === "'") {
                // Read single-quoted string
                $pos++;
                $quoteStart = $pos;

                while ($pos < $len && $this->input[$pos] !== "'") {
                    if ($this->input[$pos] === "\n") {
                        $this->line++;
                        $this->column = 1;
                    }

                    $pos++;
                }

                $value .= "'".substr($this->input, $quoteStart, $pos - $quoteStart)."'";
                if ($pos < $len) {
                    $pos++; // Skip closing quote
                }

                $singleQuoted = true;
                $wasQuoted = true;

                continue;
            }

            if ($ch === '"') {
                // Read double-quoted string
                $pos++;
                $quoteContent = '"';

                while ($pos < $len && $this->input[$pos] !== '"') {
                    if ($this->input[$pos] === '\\' && $pos + 1 < $len) {
                        $quoteContent .= $this->input[$pos].$this->input[$pos + 1];
                        $pos += 2;

                        continue;
                    }

                    if ($this->input[$pos] === "\n") {
                        $this->line++;
                        $this->column = 1;
                    }

                    $quoteContent .= $this->input[$pos];
                    $pos++;
                }

                $quoteContent .= '"';
                if ($pos < $len) {
                    $pos++; // Skip closing quote
                }

                $value .= $quoteContent;
                $wasQuoted = true;

                continue;
            }

            if ($ch === '$') {
                // Handle $(...), $(()), ${}, $var
                $result = $this->readDollarSequence($pos);
                $value .= $result['value'];
                $pos = $result['pos'];
                $wasQuoted = true;

                continue;
            }

            if ($ch === '`') {
                // Backtick command substitution
                $pos++;
                $btContent = '`';

                while ($pos < $len && $this->input[$pos] !== '`') {
                    if ($this->input[$pos] === '\\' && $pos + 1 < $len) {
                        $btContent .= $this->input[$pos].$this->input[$pos + 1];
                        $pos += 2;

                        continue;
                    }

                    $btContent .= $this->input[$pos];
                    $pos++;
                }

                $btContent .= '`';
                if ($pos < $len) {
                    $pos++;
                }

                $value .= $btContent;
                $wasQuoted = true;

                continue;
            }

            // Regular character
            $value .= $ch;
            $pos++;
        }

        $this->pos = $pos;
        $this->column = $startColumn + ($pos - $start);

        // Classify the token
        $type = $this->classifyWord($value, $wasQuoted);

        return new Token($type, $value, $start, $pos, $startLine, $startColumn, $wasQuoted, $singleQuoted);
    }

    /**
     * @return array{value: string, pos: int}
     */
    private function readDollarSequence(int $pos): array
    {
        $len = strlen($this->input);
        $pos++; // Skip $

        if ($pos >= $len) {
            return ['value' => '$', 'pos' => $pos];
        }

        $next = $this->input[$pos];

        // $(( — arithmetic expansion
        if ($next === '(' && ($pos + 1 < $len) && $this->input[$pos + 1] === '(') {
            $pos += 2;
            $depth = 1;
            $content = '$((';

            while ($pos < $len && $depth > 0) {
                if ($this->input[$pos] === '(' && ($pos + 1 < $len) && $this->input[$pos + 1] === '(') {
                    $depth++;
                    $content .= '((';
                    $pos += 2;

                    continue;
                }

                if ($this->input[$pos] === ')' && ($pos + 1 < $len) && $this->input[$pos + 1] === ')') {
                    $depth--;
                    $content .= '))';
                    $pos += 2;

                    continue;
                }

                $content .= $this->input[$pos];
                $pos++;
            }

            return ['value' => $content, 'pos' => $pos];
        }

        // $( — command substitution
        if ($next === '(') {
            $pos++;
            $depth = 1;
            $content = '$(';

            while ($pos < $len) {
                $ch = $this->input[$pos];
                if ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $content .= ')';
                        $pos++;

                        break;
                    }
                } elseif ($ch === "'" || $ch === '"') {
                    // Read through quoted strings
                    $quote = $ch;
                    $content .= $ch;
                    $pos++;
                    while ($pos < $len && $this->input[$pos] !== $quote) {
                        if ($this->input[$pos] === '\\' && $quote === '"' && $pos + 1 < $len) {
                            $content .= $this->input[$pos].$this->input[$pos + 1];
                            $pos += 2;

                            continue;
                        }

                        $content .= $this->input[$pos];
                        $pos++;
                    }

                    if ($pos < $len) {
                        $content .= $this->input[$pos];
                        $pos++;
                    }

                    continue;
                }

                $content .= $ch;
                $pos++;
            }

            return ['value' => $content, 'pos' => $pos];
        }

        // ${ — parameter expansion
        if ($next === '{') {
            $pos++;
            $depth = 1;
            $content = '${';

            while ($pos < $len && $depth > 0) {
                $ch = $this->input[$pos];
                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                }

                $content .= $ch;
                $pos++;
            }

            return ['value' => $content, 'pos' => $pos];
        }

        // $var or $special
        if (ctype_alpha($next) || $next === '_') {
            $varName = '$';
            while ($pos < $len && (ctype_alnum($this->input[$pos]) || $this->input[$pos] === '_')) {
                $varName .= $this->input[$pos];
                $pos++;
            }

            // Check for array subscript: $var[...]
            if ($pos < $len && $this->input[$pos] === '[') {
                $varName .= '[';
                $pos++;
                $bracketDepth = 1;
                while ($pos < $len && $bracketDepth > 0) {
                    if ($this->input[$pos] === '[') {
                        $bracketDepth++;
                    } elseif ($this->input[$pos] === ']') {
                        $bracketDepth--;
                    }

                    $varName .= $this->input[$pos];
                    $pos++;
                }
            }

            return ['value' => $varName, 'pos' => $pos];
        }

        // Special variables: $?, $!, $$, $#, $*, $@, $-, $0-$9
        if (in_array($next, ['?', '!', '$', '#', '*', '@', '-', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], true)) {
            $pos++;

            return ['value' => '$'.$next, 'pos' => $pos];
        }

        return ['value' => '$', 'pos' => $pos];
    }

    private function classifyWord(string $value, bool $quoted): TokenType
    {
        // Check for assignment: VAR=value or VAR+=value
        if ($this->looksLikeAssignment($value)) {
            return TokenType::ASSIGNMENT_WORD;
        }

        // Check for number (for redirections like 2>&1)
        if (! $quoted && ctype_digit($value)) {
            return TokenType::NUMBER;
        }

        // Check for reserved words (only unquoted)
        if (! $quoted && isset(self::RESERVED_WORDS[$value])) {
            return self::RESERVED_WORDS[$value];
        }

        // Check for valid name
        if (! $quoted && preg_match('/^[a-zA-Z_]\w*$/', $value)) {
            return TokenType::NAME;
        }

        return TokenType::WORD;
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
        if ($str === '') {
            return false;
        }

        if (! preg_match('/^[a-zA-Z_]\w*/', $str, $matches)) {
            return false;
        }

        $afterName = substr($str, strlen($matches[0]));

        if ($afterName === '' || $afterName === '+') {
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

            if ($depth !== 0 || $i >= $len) {
                return false;
            }

            $afterBracket = substr($afterName, $i + 1);

            return $afterBracket === '' || $afterBracket === '+';
        }

        return false;
    }

    private function registerHeredocFromLookahead(bool $stripTabs): void
    {
        $pos = $this->pos;
        $len = strlen($this->input);

        // Skip whitespace
        while ($pos < $len && ($this->input[$pos] === ' ' || $this->input[$pos] === "\t")) {
            $pos++;
        }

        if ($pos >= $len) {
            return;
        }

        $quoted = false;
        $delimiter = '';
        $ch = $this->input[$pos];

        if ($ch === "'" || $ch === '"') {
            $quoted = true;
            $quote = $ch;
            $pos++;

            while ($pos < $len && $this->input[$pos] !== $quote) {
                $delimiter .= $this->input[$pos];
                $pos++;
            }
        } elseif ($ch === '\\') {
            $quoted = true;
            $pos++;
            while ($pos < $len && ! $this->isWordBoundary($this->input[$pos]) && $this->input[$pos] !== "\n") {
                if ($this->input[$pos] === '\\' && $pos + 1 < $len) {
                    $delimiter .= $this->input[$pos + 1];
                    $pos += 2;

                    continue;
                }

                $delimiter .= $this->input[$pos];
                $pos++;
            }
        } else {
            while ($pos < $len && ! $this->isWordBoundary($this->input[$pos]) && $this->input[$pos] !== "\n") {
                $delimiter .= $this->input[$pos];
                $pos++;
            }
        }

        if ($delimiter !== '') {
            $this->pendingHeredocs[] = [
                'delimiter' => $delimiter,
                'stripTabs' => $stripTabs,
                'quoted' => $quoted,
            ];
        }
    }

    private function readHeredocContent(): void
    {
        while ($this->pendingHeredocs !== []) {
            $heredoc = array_shift($this->pendingHeredocs);
            $delimiter = $heredoc['delimiter'];
            $stripTabs = $heredoc['stripTabs'];
            $content = '';
            $len = strlen($this->input);
            $startPos = $this->pos;

            while ($this->pos < $len) {
                $lineStart = $this->pos;
                $line = '';

                while ($this->pos < $len && $this->input[$this->pos] !== "\n") {
                    $line .= $this->input[$this->pos];
                    $this->pos++;
                }

                if ($this->pos < $len) {
                    $this->pos++; // Skip newline
                    $this->line++;
                    $this->column = 1;
                }

                $trimmedLine = $stripTabs ? ltrim($line, "\t") : $line;

                if ($trimmedLine === $delimiter) {
                    break;
                }

                $content .= $line."\n";

                if (strlen($content) > $this->maxHeredocSize) {
                    throw new ParseException('Heredoc exceeds maximum size');
                }
            }

            $this->tokens[] = new Token(
                type: TokenType::HEREDOC_CONTENT,
                value: $content,
                start: $startPos,
                end: $this->pos,
                line: $this->line,
                column: $this->column,
            );
        }
    }

    private function isWordBoundary(string $char): bool
    {
        return in_array($char, [' ', "\t", "\n", ';', '&', '|', '(', ')', '<', '>'], true);
    }
}
