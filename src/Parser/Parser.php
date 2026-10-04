<?php

declare(strict_types=1);

namespace BashBox\Parser;

use BashBox\Ast\ArithmeticCommandNode;
use BashBox\Ast\ArithmeticExpressionNode;
use BashBox\Ast\AssignmentNode;
use BashBox\Ast\CaseItemNode;
use BashBox\Ast\CaseNode;
use BashBox\Ast\CommandNode;
use BashBox\Ast\CompoundCommandNode;
use BashBox\Ast\Conditional\CondAndNode;
use BashBox\Ast\Conditional\CondBinaryNode;
use BashBox\Ast\Conditional\CondGroupNode;
use BashBox\Ast\Conditional\ConditionalExpressionNode;
use BashBox\Ast\Conditional\CondNotNode;
use BashBox\Ast\Conditional\CondOrNode;
use BashBox\Ast\Conditional\CondUnaryNode;
use BashBox\Ast\Conditional\CondWordNode;
use BashBox\Ast\ConditionalCommandNode;
use BashBox\Ast\CStyleForNode;
use BashBox\Ast\ForNode;
use BashBox\Ast\FunctionDefNode;
use BashBox\Ast\GroupNode;
use BashBox\Ast\HereDocNode;
use BashBox\Ast\IfClause;
use BashBox\Ast\IfNode;
use BashBox\Ast\Parts\LiteralPart;
use BashBox\Ast\PipelineNode;
use BashBox\Ast\RedirectionNode;
use BashBox\Ast\ScriptNode;
use BashBox\Ast\SimpleCommandNode;
use BashBox\Ast\StatementNode;
use BashBox\Ast\SubshellNode;
use BashBox\Ast\UntilNode;
use BashBox\Ast\WhileNode;
use BashBox\Ast\WordNode;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Exceptions\ParseException;
use BashBox\Limits;

final class Parser
{
    /** Operators that a number (`2>`) or {name} (`{fd}>`) can prefix. */
    private const array FD_OPERATORS = [
        TokenType::LESS,
        TokenType::GREAT,
        TokenType::DLESS,
        TokenType::DGREAT,
        TokenType::LESSAND,
        TokenType::GREATAND,
        TokenType::LESSGREAT,
        TokenType::DLESSDASH,
        TokenType::CLOBBER,
        TokenType::TLESS,
    ];

    private Lexer $lexer;

    private string $input = '';

    private int $pos = 0;

    private int $parseDepth = 0;

    /**
     * The compound commands being parsed, innermost last: the word that opened each and its line.
     *
     * @var list<array{string, int}>
     */
    private array $open = [];

    public function __construct(private readonly Limits $limits = new Limits) {}

    /**
     * $line numbers the first line, for source text that sits further down a script (eval, $(...)).
     * A $substitution is the text of $(...) or <(...), parsed up to its closing `)` as bash does.
     */
    public function parse(string $input, int $line = 1, bool $substitution = false): ScriptNode
    {
        $this->input = $substitution ? $input.')' : $input;
        $this->lexer = new Lexer($this->input, $line, $this->limits);
        $this->pos = 0;
        $this->parseDepth = 0;
        $this->open = [];

        if (! $substitution) {
            return new ScriptNode($this->parseStatements());
        }

        $statements = $this->parseStatements(TokenType::RPAREN);
        $this->expect(TokenType::RPAREN);

        return new ScriptNode($statements);
    }

    /** Just past the `)` of the command substitution whose list starts at $start in $input, as bash finds it: where the list ends. */
    public static function substitutionEnd(string $input, int $start, Limits $limits, int $depth): int
    {
        $parser = new self($limits);
        $parser->input = $input;
        $parser->lexer = new Lexer($input, 1, $limits, $start, $depth);
        $parser->parseStatements(TokenType::RPAREN);

        return $parser->check(TokenType::RPAREN) ? $parser->current()->end : throw new ParseException("unexpected EOF while looking for matching `)'");
    }

    private function current(): Token
    {
        return $this->lexer->token($this->pos);
    }

    private function peek(int $offset): Token
    {
        return $this->lexer->token($this->pos + $offset);
    }

    private function advance(): Token
    {
        $token = $this->current();
        $this->pos += (int) ($token->type !== TokenType::EOF);

        return $token;
    }

    private function check(TokenType ...$types): bool
    {
        return in_array($this->current()->type, $types, true);
    }

    private function expect(TokenType $tokenType): Token
    {
        if (! $this->check($tokenType)) {
            $this->unexpectedToken();
        }

        return $this->advance();
    }

    /** Consume the word that opens a compound command, noting it for "unexpected end of file". */
    private function open(TokenType $tokenType): int
    {
        $token = $this->expect($tokenType);
        $this->open[] = [$token->value, $token->line];

        return $token->line;
    }

    private function close(TokenType $tokenType): void
    {
        $this->expect($tokenType);
        array_pop($this->open);
    }

    /** The current token as bash names it; a comment runs to the newline that bash reports. */
    private function tokenText(): string
    {
        return $this->check(TokenType::NEWLINE, TokenType::COMMENT) ? 'newline' : $this->current()->value;
    }

    private function unexpectedToken(): never
    {
        if ($this->check(TokenType::EOF)) {
            $open = end($this->open);

            throw new ParseException('syntax error: unexpected end of file'.($open === false ? '' : sprintf(" from `%s' command on line %d", ...$open)));
        }

        throw new ParseException(sprintf("syntax error near unexpected token `%s'", $this->tokenText()));
    }

    private function skipNewlines(): void
    {
        while ($this->check(TokenType::NEWLINE, TokenType::COMMENT)) {
            $this->advance();
        }
    }

    private function enterDepth(): void
    {
        if (++$this->parseDepth > $this->limits->maxAstDepth) {
            throw new ExecutionLimitException(sprintf('Nesting depth limit exceeded (%d)', $this->limits->maxAstDepth));
        }
    }

    private function isCommandStart(): bool
    {
        if ($this->check(
            TokenType::WORD,
            TokenType::NAME,
            TokenType::NUMBER,
            TokenType::ASSIGNMENT_WORD,
            TokenType::BANG,
            TokenType::TIME,
            TokenType::IN,
        )) {
            return true;
        }

        return $this->isRedirectionStart();
    }

    /**
     * Statements up to EOF or one of $terminators, each ended by `;`, `&` or a newline.
     *
     * @return list<StatementNode>
     */
    private function parseStatements(TokenType ...$terminators): array
    {
        $statements = [];
        $this->skipNewlines();

        while (! $this->check(TokenType::EOF, ...$terminators)) {
            $statements[] = $statement = $this->parseStatement();

            if (! $statement->background && ! $this->check(TokenType::NEWLINE, TokenType::COMMENT, ...$terminators)) {
                $this->expect(TokenType::SEMICOLON);
            }

            $this->skipNewlines();
        }

        return $statements;
    }

    /**
     * A compound command's list, which can't be empty.
     *
     * @return list<StatementNode>
     */
    private function parseCompoundList(TokenType ...$terminators): array
    {
        $statements = $this->parseStatements(...$terminators);

        if ($statements === []) {
            $this->unexpectedToken();
        }

        return $statements;
    }

    private function parseStatement(): StatementNode
    {
        $line = $this->current()->line;
        $pipelines = [$this->parsePipeline()];
        $operators = [];

        while ($this->check(TokenType::AND_AND, TokenType::OR_OR)) {
            $operators[] = $this->advance()->value;
            $this->skipNewlines();
            $pipelines[] = $this->parsePipeline();
        }

        $background = $this->check(TokenType::AMP);

        if ($background) {
            $this->advance();
        }

        $last = $this->lexer->token($this->pos - 1);

        return new StatementNode(
            pipelines: $pipelines,
            operators: $operators,
            background: $background,
            line: $line,
            endLine: $last->line + substr_count($this->input, "\n", $last->start, $last->end - $last->start),
        );
    }

    private function parsePipeline(): PipelineNode
    {
        $timed = $this->check(TokenType::TIME);
        $timePosix = false;

        if ($timed) {
            $this->advance();
            $timePosix = $this->current()->value === '-p';

            if ($timePosix) {
                $this->advance();
            }
        }

        $negated = $this->check(TokenType::BANG);

        if ($negated) {
            $this->advance();
        }

        // Bash runs a lone `time` or `!` on an empty command.
        $commands = [($timed || $negated) && ! $this->isCommandStart() && ! $this->compoundStart() instanceof \BashBox\Parser\TokenType ? new SimpleCommandNode : $this->parseCommand()];
        $pipeStderr = [];

        while ($this->check(TokenType::PIPE, TokenType::PIPE_AMP)) {
            $pipeStderr[] = $this->advance()->type === TokenType::PIPE_AMP;
            $this->skipNewlines();
            $commands[] = $this->parseCommand();
        }

        return new PipelineNode(
            commands: $commands,
            negated: $negated,
            timed: $timed,
            timePosix: $timePosix,
            pipeStderr: $pipeStderr !== [] ? $pipeStderr : null,
        );
    }

    private function parseCommand(): CommandNode
    {
        $this->enterDepth();
        $command = $this->parseCompoundCommand() ?? match (true) {
            $this->check(TokenType::FUNCTION),
            $this->check(TokenType::NAME, TokenType::WORD) && $this->peek(1)->type === TokenType::LPAREN => $this->parseFunctionDef(),
            $this->isCommandStart() => $this->parseSimpleCommand(),
            default => $this->unexpectedToken(),
        };
        $this->parseDepth--;

        return $command;
    }

    private function compoundStart(): ?TokenType
    {
        return $this->check(TokenType::IF, TokenType::FOR, TokenType::WHILE, TokenType::UNTIL, TokenType::CASE, TokenType::LPAREN, TokenType::LBRACE, TokenType::DPAREN_START, TokenType::DBRACK_START)
            ? $this->current()->type
            : null;
    }

    private function parseCompoundCommand(): ?CompoundCommandNode
    {
        return match ($this->compoundStart()) {
            TokenType::IF => $this->parseIf(),
            TokenType::FOR => $this->parseFor(),
            TokenType::WHILE, TokenType::UNTIL => $this->parseWhile(),
            TokenType::CASE => $this->parseCase(),
            TokenType::LPAREN => $this->parseSubshell(),
            TokenType::LBRACE => $this->parseGroup(),
            TokenType::DPAREN_START => $this->parseArithmeticCommand(),
            TokenType::DBRACK_START => $this->parseConditionalCommand(),
            default => null,
        };
    }

    private function parseSimpleCommand(): SimpleCommandNode
    {
        $assignments = [];
        $args = [];
        $redirections = [];
        $arrayArgs = [];
        $name = null;
        $line = $this->current()->line;

        while ($this->check(TokenType::ASSIGNMENT_WORD)) {
            $assignments[] = $this->parseAssignment();
        }

        while (true) {
            if ($this->isRedirectionStart()) {
                $redirections[] = $this->parseRedirection();
            } elseif (! $this->isWordToken()) {
                break;
            } elseif (! $name instanceof WordNode) {
                $name = $this->parseWord();
            } elseif ($this->current()->type === TokenType::ASSIGNMENT_WORD && str_ends_with($this->current()->value, '=') && $this->peek(1)->type === TokenType::LPAREN) {
                // `declare -A m=(...)` keeps the operand's array; the builtin sees just the name.
                $arrayArgs[count($args)] = $assignment = $this->parseAssignment();
                $args[] = new WordNode([new LiteralPart($assignment->name)]);
            } else {
                $args[] = $this->parseWord();
            }
        }

        return new SimpleCommandNode(
            name: $name,
            args: $args,
            assignments: $assignments,
            redirections: $redirections,
            line: $line,
            arrayArgs: $arrayArgs,
        );
    }

    private function isWordToken(): bool
    {
        return $this->check(
            TokenType::WORD,
            TokenType::NAME,
            TokenType::NUMBER,
            TokenType::ASSIGNMENT_WORD,
            TokenType::IN,
            // Reserved words and these operators are plain words outside command position.
            TokenType::LBRACE,
            TokenType::RBRACE,
            TokenType::DBRACK_START,
            TokenType::DBRACK_END,
            TokenType::BANG,
            TokenType::IF,
            TokenType::THEN,
            TokenType::ELSE,
            TokenType::ELIF,
            TokenType::FI,
            TokenType::DO,
            TokenType::DONE,
            TokenType::CASE,
            TokenType::ESAC,
            TokenType::FOR,
            TokenType::SELECT,
            TokenType::WHILE,
            TokenType::UNTIL,
            TokenType::FUNCTION,
            TokenType::TIME,
            TokenType::COPROC,
        );
    }

    /** A redirection operator, or a number or {name} touching one: `2>f` redirects fd 2 but `2 >f` passes 2. */
    private function isRedirectionStart(): bool
    {
        if ($this->check(TokenType::NUMBER, TokenType::FD_VARIABLE)) {
            return $this->current()->end === $this->peek(1)->start && in_array($this->peek(1)->type, self::FD_OPERATORS, true);
        }

        return $this->check(TokenType::AND_GREAT, TokenType::AND_DGREAT, ...self::FD_OPERATORS);
    }

    private function parseWord(): WordNode
    {
        return new WordNode([new LiteralPart($this->wordToken()->value)]);
    }

    private function wordToken(): Token
    {
        if (! $this->isWordToken()) {
            $this->unexpectedToken();
        }

        return $this->advance();
    }

    private function parseAssignment(): AssignmentNode
    {
        $token = $this->advance();

        // The lexer only emits ASSIGNMENT_WORD for NAME[subscript]+?=value.
        preg_match('/^([a-zA-Z_]\w*(?:\[.*?\])?)(\+?)=(.*)$/s', $token->value, $m);
        [, $lhs, $plus, $rhs] = $m;
        $append = $plus === '+';

        if ($rhs === '' && $this->check(TokenType::LPAREN)) {
            $this->advance();

            return $this->parseArrayAssignment($lhs, $append, $token->line);
        }

        $rhsWord = $rhs !== '' ? new WordNode([new LiteralPart($rhs)]) : null;

        return new AssignmentNode(
            name: $lhs,
            value: $rhsWord,
            append: $append,
            line: $token->line,
        );
    }

    private function parseArrayAssignment(string $name, bool $append, int $line): AssignmentNode
    {
        $elements = [];
        $this->skipNewlines();

        while ($this->isWordToken()) {
            $elements[] = $this->parseWord();
            $this->skipNewlines();
        }

        if ($this->check(TokenType::EOF)) {
            throw new ParseException("unexpected EOF while looking for matching `)'");
        }

        $this->expect(TokenType::RPAREN);

        return new AssignmentNode(
            name: $name,
            append: $append,
            array: $elements,
            line: $line,
        );
    }

    private function parseRedirection(): RedirectionNode
    {
        $fd = $this->check(TokenType::NUMBER) ? (int) $this->advance()->value : null;
        $fdVariable = $this->check(TokenType::FD_VARIABLE) ? $this->advance()->value : null;
        $token = $this->advance();

        $target = $this->wordToken();

        if ($token->type === TokenType::DLESS || $token->type === TokenType::DLESSDASH) {
            // The lexer puts a heredoc's body right after its delimiter.
            $body = $this->advance();

            return new RedirectionNode(
                operator: $token->value,
                target: new HereDocNode($target->value, new WordNode([new LiteralPart($body->value)]), $token->type === TokenType::DLESSDASH, $body->quoted),
                fd: $fd ?? 0,
                fdVariable: $fdVariable,
            );
        }

        return new RedirectionNode(
            operator: $token->value,
            target: new WordNode([new LiteralPart($target->value)]),
            fd: $fd ?? (in_array($token->type, [TokenType::LESS, TokenType::LESSAND, TokenType::LESSGREAT, TokenType::TLESS], true) ? 0 : 1),
            fdVariable: $fdVariable,
        );
    }

    private function parseIf(): IfNode
    {
        $line = $this->open(TokenType::IF);
        $clauses = [];
        $elseBody = null;

        while (true) {
            $condition = $this->parseCompoundList(TokenType::THEN);
            $this->expect(TokenType::THEN);
            $clauses[] = new IfClause($condition, $this->parseCompoundList(TokenType::ELIF, TokenType::ELSE, TokenType::FI));

            if (! $this->check(TokenType::ELIF)) {
                break;
            }

            $this->advance();
        }

        if ($this->check(TokenType::ELSE)) {
            $this->advance();
            $elseBody = $this->parseCompoundList(TokenType::FI);
        }

        $this->close(TokenType::FI);

        return new IfNode(
            clauses: $clauses,
            elseBody: $elseBody,
            redirections: $this->parseTrailingRedirections(),
            line: $line,
        );
    }

    private function parseFor(): ForNode|CStyleForNode
    {
        $line = $this->open(TokenType::FOR);

        if ($this->check(TokenType::DPAREN_START)) {
            return $this->parseCStyleFor($line);
        }

        $variable = $this->wordToken()->value;
        $words = null;
        $this->skipNewlines();

        if ($this->check(TokenType::IN)) {
            $this->advance();
            $words = [];

            while ($this->isWordToken()) {
                $words[] = $this->parseWord();
            }
        }

        return new ForNode(
            variable: $variable,
            words: $words,
            body: $this->parseDoGroup(),
            redirections: $this->parseTrailingRedirections(),
            line: $line,
        );
    }

    private function parseCStyleFor(int $line): CStyleForNode
    {
        // Bash prints each clause without its leading blanks.
        $start = $this->advance()->end;
        $parts = [];

        // The lexer pairs every (( with a )).
        while (! $this->check(TokenType::DPAREN_END)) {
            $token = $this->advance();

            if ($token->type === TokenType::SEMICOLON && count($parts) < 2) {
                $parts[] = substr($this->input, $start, $token->start - $start);
                $start = $token->end;
            }
        }

        $parts[] = substr($this->input, $start, $this->advance()->start - $start);

        [$init, $condition, $update] = array_map(
            fn (string $part): ?ArithmeticExpressionNode => trim($part) === '' ? null : new ArithmeticExpressionNode(ltrim($part)),
            array_pad($parts, 3, ''),
        );

        return new CStyleForNode(
            init: $init,
            condition: $condition,
            update: $update,
            body: $this->parseDoGroup(),
            redirections: $this->parseTrailingRedirections(),
            line: $line,
        );
    }

    /**
     * The `[;] do list done` after a for loop's header.
     *
     * @return list<StatementNode>
     */
    private function parseDoGroup(): array
    {
        if ($this->check(TokenType::SEMICOLON)) {
            $this->advance();
        }

        $this->skipNewlines();
        $this->expect(TokenType::DO);
        $body = $this->parseCompoundList(TokenType::DONE);
        $this->close(TokenType::DONE);

        return $body;
    }

    private function parseWhile(): WhileNode|UntilNode
    {
        $until = $this->check(TokenType::UNTIL);
        $line = $this->open($this->current()->type);
        $condition = $this->parseCompoundList(TokenType::DO);
        $this->expect(TokenType::DO);
        $body = $this->parseCompoundList(TokenType::DONE);
        $this->close(TokenType::DONE);
        $redirections = $this->parseTrailingRedirections();

        return $until
            ? new UntilNode($condition, $body, $redirections, $line)
            : new WhileNode($condition, $body, $redirections, $line);
    }

    private function parseCase(): CaseNode
    {
        $line = $this->open(TokenType::CASE);
        $wordNode = $this->parseWord();
        $this->skipNewlines();
        $this->expect(TokenType::IN);
        $this->skipNewlines();
        $items = [];

        while (! $this->check(TokenType::ESAC)) {
            if ($this->check(TokenType::LPAREN)) {
                $this->advance();
            }

            $patterns = [$this->parseWord()];

            while ($this->check(TokenType::PIPE)) {
                $this->advance();
                $patterns[] = $this->parseWord();
            }

            $this->expect(TokenType::RPAREN);
            $body = $this->parseStatements(TokenType::DSEMI, TokenType::SEMI_AND, TokenType::SEMI_SEMI_AND, TokenType::ESAC);

            $items[] = new CaseItemNode(
                patterns: $patterns,
                body: $body,
                terminator: $this->check(TokenType::ESAC, TokenType::EOF) ? ';;' : $this->advance()->value,
            );

            $this->skipNewlines();
        }

        $this->close(TokenType::ESAC);

        return new CaseNode(
            word: $wordNode,
            items: $items,
            redirections: $this->parseTrailingRedirections(),
            line: $line,
        );
    }

    private function parseSubshell(): SubshellNode
    {
        $line = $this->open(TokenType::LPAREN);
        $body = $this->parseCompoundList(TokenType::RPAREN);
        $this->close(TokenType::RPAREN);

        return new SubshellNode(
            body: $body,
            redirections: $this->parseTrailingRedirections(),
            line: $line,
        );
    }

    private function parseGroup(): GroupNode
    {
        $line = $this->open(TokenType::LBRACE);
        $body = $this->parseCompoundList(TokenType::RBRACE);
        $this->close(TokenType::RBRACE);

        return new GroupNode(
            body: $body,
            redirections: $this->parseTrailingRedirections(),
            line: $line,
        );
    }

    private function parseArithmeticCommand(): ArithmeticCommandNode
    {
        $token = $this->advance();

        // The lexer pairs every (( with a )).
        while (! $this->check(TokenType::DPAREN_END)) {
            $this->advance();
        }

        $text = substr($this->input, $token->end, $this->advance()->start - $token->end);

        return new ArithmeticCommandNode(
            expression: new ArithmeticExpressionNode($text),
            redirections: $this->parseTrailingRedirections(),
            line: $token->line,
        );
    }

    private function parseConditionalCommand(): ConditionalCommandNode
    {
        $line = $this->advance()->line;
        $conditionalExpressionNode = $this->parseCondOr();

        if (! $this->check(TokenType::DBRACK_END)) {
            $this->condError("unexpected token `%s', conditional binary operator expected");
        }

        $this->advance();

        return new ConditionalCommandNode(
            expression: $conditionalExpressionNode,
            redirections: $this->parseTrailingRedirections(),
            line: $line,
        );
    }

    private function parseFunctionDef(): FunctionDefNode
    {
        $line = $this->current()->line;

        if ($this->check(TokenType::FUNCTION)) {
            $this->advance();
        }

        $name = $this->wordToken()->value;

        if ($this->check(TokenType::LPAREN)) {
            $this->advance();
            $this->expect(TokenType::RPAREN);
        }

        $this->skipNewlines();

        return new FunctionDefNode(
            name: $name,
            body: $this->parseCompoundCommand() ?? $this->unexpectedToken(),
            line: $line,
        );
    }

    /**
     * @return list<RedirectionNode>
     */
    private function parseTrailingRedirections(): array
    {
        $redirections = [];

        while ($this->isRedirectionStart()) {
            $redirections[] = $this->parseRedirection();
        }

        return $redirections;
    }

    private function parseCondOr(): ConditionalExpressionNode
    {
        $left = $this->parseCondAnd();

        while ($this->check(TokenType::OR_OR)) {
            $this->advance();
            $left = new CondOrNode($left, $this->parseCondAnd());
        }

        return $left;
    }

    private function parseCondAnd(): ConditionalExpressionNode
    {
        $left = $this->parseCondPrimary();

        while ($this->check(TokenType::AND_AND)) {
            $this->advance();
            $left = new CondAndNode($left, $this->parseCondPrimary());
        }

        return $left;
    }

    private function parseCondPrimary(): ConditionalExpressionNode
    {
        $this->enterDepth();

        if ($this->check(TokenType::BANG)) {
            $this->advance();
            $node = new CondNotNode($this->parseCondPrimary());
        } elseif ($this->check(TokenType::LPAREN)) {
            $this->advance();
            $node = new CondGroupNode($this->parseCondOr());

            if (! $this->check(TokenType::RPAREN)) {
                $this->condError("unexpected token `%s', expected `)'");
            }

            $this->advance();
        } elseif ($this->isCondUnaryOperator()) {
            $op = $this->advance()->value;
            $node = new CondUnaryNode($op, $this->condOperand('unary'));
        } else {
            $word = $this->condOperand();

            if ($this->isCondBinaryOperator()) {
                $op = $this->advance()->value;
                $node = new CondBinaryNode($op, $word, $op === '=~' ? $this->condRegex() : $this->condOperand('binary'));
            } else {
                $node = new CondWordNode($word);
            }
        }

        $this->parseDepth--;

        return $node;
    }

    /** A word inside [[ ]]; $operator names the operator it's an argument to. */
    private function condOperand(?string $operator = null): WordNode
    {
        if (! $this->isWordToken() || $this->check(TokenType::DBRACK_END)) {
            $this->condError($operator === null ? "syntax error near `%s'" : sprintf("unexpected argument `%%s' to conditional %s operator", $operator));
        }

        return $this->parseWord();
    }

    /** The regex after =~: tokens up to the first blank outside parentheses, as source text. */
    private function condRegex(): WordNode
    {
        $start = $this->current()->start;
        $end = $start;
        $depth = 0;

        while (! $this->check(TokenType::NEWLINE, TokenType::EOF) && ($depth > 0 || ($this->current()->start === $end && ! $this->check(TokenType::DBRACK_END, TokenType::AND_AND, TokenType::OR_OR)))) {
            $token = $this->advance();
            $depth += match ($token->type) {
                TokenType::LPAREN => 1,
                TokenType::RPAREN => -1,
                default => 0,
            };
            $end = $token->end;
        }

        if ($end === $start) {
            $this->condError("unexpected argument `%s' to conditional binary operator");
        }

        return new WordNode([new LiteralPart(substr($this->input, $start, $end - $start))]);
    }

    private function condError(string $format): never
    {
        throw new ParseException(sprintf($format, $this->tokenText()));
    }

    private function isCondUnaryOperator(): bool
    {
        return in_array($this->current()->value, [
            '-a', '-b', '-c', '-d', '-e', '-f', '-g', '-h', '-k', '-p',
            '-r', '-s', '-t', '-u', '-w', '-x', '-G', '-L', '-N', '-O',
            '-S', '-z', '-n', '-o', '-v', '-R',
        ], true);
    }

    private function isCondBinaryOperator(): bool
    {
        return in_array($this->current()->value, [
            '=', '==', '!=', '=~', '<', '>',
            '-eq', '-ne', '-lt', '-le', '-gt', '-ge',
            '-nt', '-ot', '-ef',
        ], true);
    }
}
