<?php

declare(strict_types=1);

namespace BashBox\Parser;

use BashBox\Ast\ArithmeticCommandNode;
use BashBox\Ast\ArithmeticExpressionNode;
use BashBox\Ast\AssignmentNode;
use BashBox\Ast\CaseItemNode;
use BashBox\Ast\CaseNode;
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
use BashBox\Exceptions\ParseException;

final class Parser
{
    /** @var list<Token> */
    private array $tokens = [];

    private int $pos = 0;

    private int $parseIterations = 0;

    private int $parseDepth = 0;

    /** @var list<array{redirect: RedirectionNode, delimiter: string, stripTabs: bool, quoted: bool}> */
    private array $pendingHeredocs = [];

    public function parse(string $input): ScriptNode
    {
        $lexer = new Lexer($input);
        $this->tokens = $lexer->tokenize();
        $this->pos = 0;
        $this->pendingHeredocs = [];
        $this->parseIterations = 0;
        $this->parseDepth = 0;

        return $this->parseScript();
    }

    private function current(): Token
    {
        return $this->tokens[$this->pos] ?? $this->tokens[count($this->tokens) - 1];
    }

    private function peek(int $offset = 0): Token
    {
        return $this->tokens[$this->pos + $offset] ?? $this->tokens[count($this->tokens) - 1];
    }

    private function advance(): Token
    {
        $token = $this->current();

        if ($this->pos < count($this->tokens) - 1) {
            $this->pos++;
        }

        return $token;
    }

    private function check(TokenType ...$types): bool
    {
        $current = $this->current()->type;

        return in_array($current, $types, true);
    }

    private function expect(TokenType $type, ?string $message = null): Token
    {
        if ($this->check($type)) {
            return $this->advance();
        }

        $token = $this->current();

        throw new ParseException($message ?? sprintf('Expected %s, got %s', $type->value, $token->type->value));
    }

    private function error(string $message): never
    {
        throw new ParseException($message);
    }

    private function skipNewlines(): void
    {
        while ($this->check(TokenType::NEWLINE, TokenType::COMMENT)) {
            if ($this->check(TokenType::NEWLINE)) {
                $this->advance();
                $this->processHeredocs();
            } else {
                $this->advance();
            }
        }
    }

    private function skipSeparators(): void
    {
        while (true) {
            if ($this->check(TokenType::NEWLINE)) {
                $this->advance();
                $this->processHeredocs();

                continue;
            }

            if ($this->check(TokenType::SEMICOLON, TokenType::COMMENT)) {
                $this->advance();

                continue;
            }

            break;
        }
    }

    private function processHeredocs(): void
    {
        foreach ($this->pendingHeredocs as $heredoc) {
            if ($this->check(TokenType::HEREDOC_CONTENT)) {
                $content = $this->advance();
                $contentWord = new WordNode([new LiteralPart($content->value)]);

                $heredoc['redirect']->target = new HereDocNode(
                    delimiter: $heredoc['delimiter'],
                    content: $contentWord,
                    stripTabs: $heredoc['stripTabs'],
                    quoted: $heredoc['quoted'],
                );
            }
        }

        $this->pendingHeredocs = [];
    }

    private function checkIteration(): void
    {
        $this->parseIterations++;

        if ($this->parseIterations > ParserLimits::MAX_PARSE_ITERATIONS) {
            $this->error('Maximum parse iterations exceeded');
        }
    }

    private function enterDepth(): void
    {
        $this->parseDepth++;

        if ($this->parseDepth > ParserLimits::MAX_PARSER_DEPTH) {
            $this->error('Maximum parser nesting depth exceeded');
        }
    }

    private function exitDepth(): void
    {
        $this->parseDepth--;
    }

    private function isCommandStart(): bool
    {
        if ($this->check(
            TokenType::WORD,
            TokenType::NAME,
            TokenType::NUMBER,
            TokenType::ASSIGNMENT_WORD,
            TokenType::IF,
            TokenType::FOR,
            TokenType::WHILE,
            TokenType::UNTIL,
            TokenType::CASE,
            TokenType::LPAREN,
            TokenType::LBRACE,
            TokenType::DPAREN_START,
            TokenType::DBRACK_START,
            TokenType::FUNCTION,
            TokenType::BANG,
            TokenType::TIME,
            TokenType::IN,
        )) {
            return true;
        }

        return $this->isRedirectionToken();
    }

    private function isRedirectionToken(): bool
    {
        return $this->check(
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
            TokenType::AND_GREAT,
            TokenType::AND_DGREAT,
        );
    }

    private function isStatementEnd(): bool
    {
        return $this->check(
            TokenType::EOF,
            TokenType::NEWLINE,
            TokenType::SEMICOLON,
            TokenType::AMP,
            TokenType::AND_AND,
            TokenType::OR_OR,
            TokenType::RPAREN,
            TokenType::RBRACE,
            TokenType::DSEMI,
            TokenType::SEMI_AND,
            TokenType::SEMI_SEMI_AND,
        );
    }

    // =========================================================================
    // SCRIPT PARSING
    // =========================================================================

    private function parseScript(): ScriptNode
    {
        $statements = [];
        $this->skipNewlines();

        while (! $this->check(TokenType::EOF)) {
            $this->checkIteration();
            $posBefore = $this->pos;

            $stmt = $this->parseStatement();

            if ($stmt instanceof \BashBox\Ast\StatementNode) {
                $statements[] = $stmt;
            }

            $this->skipSeparators();

            if ($this->pos === $posBefore && ! $this->check(TokenType::EOF)) {
                $this->advance();
            }
        }

        return new ScriptNode($statements);
    }

    // =========================================================================
    // STATEMENT PARSING
    // =========================================================================

    private function parseStatement(): ?StatementNode
    {
        $this->skipNewlines();

        if (! $this->isCommandStart()) {
            return null;
        }

        $pipelines = [];
        $operators = [];
        $background = false;

        $pipelines[] = $this->parsePipeline();

        while ($this->check(TokenType::AND_AND, TokenType::OR_OR)) {
            $op = $this->advance();
            $operators[] = $op->type === TokenType::AND_AND ? '&&' : '||';
            $this->skipNewlines();
            $pipelines[] = $this->parsePipeline();
        }

        if ($this->check(TokenType::AMP)) {
            $this->advance();
            $background = true;
        }

        return new StatementNode(
            pipelines: $pipelines,
            operators: $operators,
            background: $background,
            line: ($cmd = $pipelines[0]->commands[0]) instanceof SimpleCommandNode || $cmd instanceof FunctionDefNode ? $cmd->line : null,
        );
    }

    // =========================================================================
    // PIPELINE PARSING
    // =========================================================================

    private function parsePipeline(): PipelineNode
    {
        $this->checkIteration();

        $negated = false;
        $timed = false;
        $timePosix = false;

        if ($this->check(TokenType::TIME)) {
            $this->advance();
            $timed = true;

            if ($this->check(TokenType::WORD) && $this->current()->value === '-p') {
                $this->advance();
                $timePosix = true;
            }
        }

        if ($this->check(TokenType::BANG)) {
            $this->advance();
            $negated = true;
        }

        $commands = [];
        $pipeStderr = [];

        $commands[] = $this->parseCommand();

        while ($this->check(TokenType::PIPE, TokenType::PIPE_AMP)) {
            $pipeToken = $this->advance();
            $pipeStderr[] = $pipeToken->type === TokenType::PIPE_AMP;
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

    // =========================================================================
    // COMMAND PARSING
    // =========================================================================

    private function parseCommand(): SimpleCommandNode|CompoundCommandNode|FunctionDefNode
    {
        $this->checkIteration();
        $this->enterDepth();

        try {
            // Check for compound commands first
            if ($this->check(TokenType::IF)) {
                return $this->parseIf();
            }

            if ($this->check(TokenType::FOR)) {
                return $this->parseFor();
            }

            if ($this->check(TokenType::WHILE)) {
                return $this->parseWhile();
            }

            if ($this->check(TokenType::UNTIL)) {
                return $this->parseUntil();
            }

            if ($this->check(TokenType::CASE)) {
                return $this->parseCase();
            }

            if ($this->check(TokenType::LPAREN)) {
                return $this->parseSubshell();
            }

            if ($this->check(TokenType::LBRACE)) {
                return $this->parseGroup();
            }

            if ($this->check(TokenType::DPAREN_START)) {
                return $this->parseArithmeticCommand();
            }

            if ($this->check(TokenType::DBRACK_START)) {
                return $this->parseConditionalCommand();
            }

            if ($this->check(TokenType::FUNCTION)) {
                return $this->parseFunctionDef();
            }

            // Check for function definition: name () { ... }
            if (
                ($this->check(TokenType::NAME, TokenType::WORD))
                && $this->peek(1)->type === TokenType::LPAREN
                && $this->peek(2)->type === TokenType::RPAREN
            ) {
                return $this->parseFunctionDef();
            }

            return $this->parseSimpleCommand();
        } finally {
            $this->exitDepth();
        }
    }

    // =========================================================================
    // SIMPLE COMMAND
    // =========================================================================

    private function parseSimpleCommand(): SimpleCommandNode
    {
        $assignments = [];
        $args = [];
        $redirections = [];
        $name = null;
        $line = $this->current()->line;

        // Parse prefix assignments
        while ($this->check(TokenType::ASSIGNMENT_WORD)) {
            $assignments[] = $this->parseAssignment();
        }

        // Parse redirections before command name
        while ($this->isRedirectionToken()) {
            $redirections[] = $this->parseRedirection();
        }

        // Parse command name
        if ($this->isWordToken()) {
            $name = $this->parseWord();
        }

        // Parse arguments and redirections
        while (! $this->isStatementEnd() && ! $this->check(TokenType::PIPE, TokenType::PIPE_AMP)) {
            $this->checkIteration();

            if ($this->isRedirectionToken()) {
                $redirections[] = $this->parseRedirection();

                continue;
            }

            if ($this->check(TokenType::NUMBER) && $this->isRedirectionAfterNumber()) {
                $redirections[] = $this->parseRedirection();

                continue;
            }

            if ($this->isWordToken() || $this->check(TokenType::ASSIGNMENT_WORD)) {
                $args[] = $this->parseWord();

                continue;
            }

            break;
        }

        return new SimpleCommandNode(
            name: $name,
            args: $args,
            assignments: $assignments,
            redirections: $redirections,
            line: $line,
        );
    }

    private function isWordToken(): bool
    {
        return $this->check(
            TokenType::WORD,
            TokenType::NAME,
            TokenType::NUMBER,
            TokenType::IN,
            TokenType::FD_VARIABLE,
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

    private function isRedirectionAfterNumber(): bool
    {
        $next = $this->peek(1);

        return in_array($next->type, [
            TokenType::LESS,
            TokenType::GREAT,
            TokenType::DGREAT,
            TokenType::LESSAND,
            TokenType::GREATAND,
            TokenType::LESSGREAT,
            TokenType::CLOBBER,
        ], true);
    }

    private function parseWord(): WordNode
    {
        $token = $this->advance();

        return $this->tokenToWordNode($token);
    }

    private function tokenToWordNode(Token $token): WordNode
    {
        $value = $token->value;

        return new WordNode([new LiteralPart($value)]);
    }

    private function parseAssignment(): AssignmentNode
    {
        $token = $this->advance();
        $value = $token->value;

        // Find = sign
        $eqPos = strpos($value, '=');

        if ($eqPos === false) {
            return new AssignmentNode(name: $value, line: $token->line);
        }

        $lhs = substr($value, 0, $eqPos);
        $rhs = substr($value, $eqPos + 1);

        $append = false;

        if (str_ends_with($lhs, '+')) {
            $lhs = substr($lhs, 0, -1);
            $append = true;
        }

        if ($rhs === '' && $this->check(TokenType::LPAREN)) {
            $this->advance();

            return $this->parseArrayAssignment($lhs, $append, $token->line);
        }

        // Check for array assignment: VAR=(...)
        if ($rhs === '(') {
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

        while (! $this->check(TokenType::RPAREN, TokenType::EOF)) {
            $this->skipNewlines();

            if ($this->check(TokenType::RPAREN)) {
                break;
            }

            $elements[] = $this->parseWord();
        }

        if ($this->check(TokenType::RPAREN)) {
            $this->advance();
        }

        return new AssignmentNode(
            name: $name,
            append: $append,
            array: $elements,
            line: $line,
        );
    }

    private function parseRedirection(): RedirectionNode
    {
        $fd = null;

        // Check for number prefix: 2>
        if ($this->check(TokenType::NUMBER)) {
            $fd = (int) $this->current()->value;
            $this->advance();
        }

        $opToken = $this->advance();
        $operator = $opToken->value;

        // Heredoc operators
        if ($opToken->type === TokenType::DLESS || $opToken->type === TokenType::DLESSDASH) {
            $stripTabs = $opToken->type === TokenType::DLESSDASH;
            $delimToken = $this->advance();
            $delimiter = $delimToken->value;
            $quoted = $delimToken->quoted || $delimToken->singleQuoted;

            // Strip quotes from delimiter
            $delimiter = trim($delimiter, "'\"");

            $target = new WordNode([new LiteralPart('')]);
            $redirect = new RedirectionNode(
                operator: $operator,
                target: $target,
                fd: $fd ?? 0,
            );

            $this->pendingHeredocs[] = [
                'redirect' => $redirect,
                'delimiter' => $delimiter,
                'stripTabs' => $stripTabs,
                'quoted' => $quoted,
            ];

            return $redirect;
        }

        // Here-string: <<<
        if ($opToken->type === TokenType::TLESS) {
            $target = $this->parseWord();

            return new RedirectionNode(
                operator: $operator,
                target: $target,
                fd: $fd ?? 0,
            );
        }

        // Regular redirect: target is a word
        $target = $this->parseWord();

        // Set default fd based on operator
        if ($fd === null) {
            $fd = match ($opToken->type) {
                TokenType::LESS, TokenType::LESSAND, TokenType::LESSGREAT => 0,
                default => 1,
            };
        }

        return new RedirectionNode(
            operator: $operator,
            target: $target,
            fd: $fd,
        );
    }

    // =========================================================================
    // COMPOUND COMMANDS
    // =========================================================================

    private function parseIf(): IfNode
    {
        $this->expect(TokenType::IF);
        $line = $this->current()->line;
        $clauses = [];

        // Parse if/elif clauses
        $condition = $this->parseCompoundList();
        $this->expect(TokenType::THEN);
        $body = $this->parseCompoundList();
        $clauses[] = new IfClause($condition, $body);

        while ($this->check(TokenType::ELIF)) {
            $this->advance();
            $condition = $this->parseCompoundList();
            $this->expect(TokenType::THEN);
            $body = $this->parseCompoundList();
            $clauses[] = new IfClause($condition, $body);
        }

        $elseBody = null;

        if ($this->check(TokenType::ELSE)) {
            $this->advance();
            $elseBody = $this->parseCompoundList();
        }

        $this->expect(TokenType::FI);

        $redirections = $this->parseTrailingRedirections();

        return new IfNode(
            clauses: $clauses,
            elseBody: $elseBody,
            redirections: $redirections,
            line: $line,
        );
    }

    private function parseFor(): ForNode|CStyleForNode
    {
        $this->expect(TokenType::FOR);
        $line = $this->current()->line;

        // C-style for: for (( ... ))
        if ($this->check(TokenType::DPAREN_START)) {
            return $this->parseCStyleFor($line);
        }

        $varToken = $this->advance();
        $variable = $varToken->value;
        $words = null;

        $this->skipNewlines();

        if ($this->check(TokenType::IN)) {
            $this->advance();
            $words = [];

            while (! $this->check(TokenType::SEMICOLON, TokenType::NEWLINE, TokenType::EOF, TokenType::DO)) {
                $words[] = $this->parseWord();
            }
        }

        $this->skipSeparators();
        $this->expect(TokenType::DO);
        $body = $this->parseCompoundList();
        $this->expect(TokenType::DONE);

        $redirections = $this->parseTrailingRedirections();

        return new ForNode(
            variable: $variable,
            words: $words,
            body: $body,
            redirections: $redirections,
            line: $line,
        );
    }

    private function parseCStyleFor(int $line): CStyleForNode
    {
        $this->expect(TokenType::DPAREN_START);

        // Parse init; cond; update as raw text between semicolons
        // For now, create placeholder expressions
        $init = null;
        $condition = null;
        $update = null;

        // Read tokens until ))
        $parts = [[], [], []];
        $partIndex = 0;

        while (! $this->check(TokenType::DPAREN_END, TokenType::EOF)) {
            if ($this->check(TokenType::SEMICOLON)) {
                $this->advance();
                $partIndex = min($partIndex + 1, 2);

                continue;
            }

            $parts[$partIndex][] = $this->advance();
        }

        $this->expect(TokenType::DPAREN_END);

        if ($parts[0] !== []) {
            $init = $this->tokensToArithExpr($parts[0]);
        }

        if ($parts[1] !== []) {
            $condition = $this->tokensToArithExpr($parts[1]);
        }

        if ($parts[2] !== []) {
            $update = $this->tokensToArithExpr($parts[2]);
        }

        $this->skipSeparators();
        $this->expect(TokenType::DO);
        $body = $this->parseCompoundList();
        $this->expect(TokenType::DONE);

        $redirections = $this->parseTrailingRedirections();

        return new CStyleForNode(
            init: $init,
            condition: $condition,
            update: $update,
            body: $body,
            redirections: $redirections,
            line: $line,
        );
    }

    private function parseWhile(): WhileNode
    {
        $this->expect(TokenType::WHILE);
        $line = $this->current()->line;

        $condition = $this->parseCompoundList();
        $this->expect(TokenType::DO);
        $body = $this->parseCompoundList();
        $this->expect(TokenType::DONE);

        $redirections = $this->parseTrailingRedirections();

        return new WhileNode(
            condition: $condition,
            body: $body,
            redirections: $redirections,
            line: $line,
        );
    }

    private function parseUntil(): UntilNode
    {
        $this->expect(TokenType::UNTIL);
        $line = $this->current()->line;

        $condition = $this->parseCompoundList();
        $this->expect(TokenType::DO);
        $body = $this->parseCompoundList();
        $this->expect(TokenType::DONE);

        $redirections = $this->parseTrailingRedirections();

        return new UntilNode(
            condition: $condition,
            body: $body,
            redirections: $redirections,
            line: $line,
        );
    }

    private function parseCase(): CaseNode
    {
        $this->expect(TokenType::CASE);
        $line = $this->current()->line;

        $word = $this->parseWord();
        $this->skipNewlines();
        $this->expect(TokenType::IN);
        $this->skipNewlines();

        $items = [];

        while (! $this->check(TokenType::ESAC, TokenType::EOF)) {
            $this->skipNewlines();

            if ($this->check(TokenType::ESAC)) {
                break;
            }

            // Skip optional ( before pattern
            if ($this->check(TokenType::LPAREN)) {
                $this->advance();
            }

            $patterns = [];
            $patterns[] = $this->parseWord();

            while ($this->check(TokenType::PIPE)) {
                $this->advance();
                $patterns[] = $this->parseWord();
            }

            $this->expect(TokenType::RPAREN);

            $body = [];

            while (! $this->check(TokenType::DSEMI, TokenType::SEMI_AND, TokenType::SEMI_SEMI_AND, TokenType::ESAC, TokenType::EOF)) {
                $stmt = $this->parseStatement();

                if ($stmt instanceof \BashBox\Ast\StatementNode) {
                    $body[] = $stmt;
                }

                $this->skipSeparators();

                if ($this->check(TokenType::DSEMI, TokenType::SEMI_AND, TokenType::SEMI_SEMI_AND, TokenType::ESAC)) {
                    break;
                }
            }

            $terminator = ';;';

            if ($this->check(TokenType::DSEMI)) {
                $this->advance();
                $terminator = ';;';
            } elseif ($this->check(TokenType::SEMI_AND)) {
                $this->advance();
                $terminator = ';&';
            } elseif ($this->check(TokenType::SEMI_SEMI_AND)) {
                $this->advance();
                $terminator = ';;&';
            }

            $items[] = new CaseItemNode(
                patterns: $patterns,
                body: $body,
                terminator: $terminator,
            );
        }

        $this->expect(TokenType::ESAC);

        $redirections = $this->parseTrailingRedirections();

        return new CaseNode(
            word: $word,
            items: $items,
            redirections: $redirections,
            line: $line,
        );
    }

    private function parseSubshell(): SubshellNode
    {
        $this->expect(TokenType::LPAREN);
        $line = $this->current()->line;

        $body = $this->parseCompoundList();

        $this->expect(TokenType::RPAREN);

        $redirections = $this->parseTrailingRedirections();

        return new SubshellNode(
            body: $body,
            redirections: $redirections,
            line: $line,
        );
    }

    private function parseGroup(): GroupNode
    {
        $this->expect(TokenType::LBRACE);
        $line = $this->current()->line;

        $body = $this->parseCompoundList();

        $this->expect(TokenType::RBRACE);

        $redirections = $this->parseTrailingRedirections();

        return new GroupNode(
            body: $body,
            redirections: $redirections,
            line: $line,
        );
    }

    private function parseArithmeticCommand(): ArithmeticCommandNode
    {
        $this->expect(TokenType::DPAREN_START);
        $line = $this->current()->line;

        $exprTokens = [];

        while (! $this->check(TokenType::DPAREN_END, TokenType::EOF)) {
            $exprTokens[] = $this->advance();
        }

        $this->expect(TokenType::DPAREN_END);

        $expression = $this->tokensToArithExpr($exprTokens);

        $redirections = $this->parseTrailingRedirections();

        return new ArithmeticCommandNode(
            expression: $expression,
            redirections: $redirections,
            line: $line,
        );
    }

    private function parseConditionalCommand(): ConditionalCommandNode
    {
        $this->expect(TokenType::DBRACK_START);
        $line = $this->current()->line;

        $expression = $this->parseConditionalExpression();

        $this->expect(TokenType::DBRACK_END);

        $redirections = $this->parseTrailingRedirections();

        return new ConditionalCommandNode(
            expression: $expression,
            redirections: $redirections,
            line: $line,
        );
    }

    private function parseFunctionDef(): FunctionDefNode
    {
        $line = $this->current()->line;

        if ($this->check(TokenType::FUNCTION)) {
            $this->advance();
        }

        $nameToken = $this->advance();
        $name = $nameToken->value;

        // Skip optional ()
        if ($this->check(TokenType::LPAREN)) {
            $this->advance();
            $this->expect(TokenType::RPAREN);
        }

        $this->skipNewlines();

        $body = $this->parseCommand();

        if (! ($body instanceof CompoundCommandNode)) {
            $this->error('Function body must be a compound command');
        }

        $redirections = $this->parseTrailingRedirections();

        return new FunctionDefNode(
            name: $name,
            body: $body,
            redirections: $redirections,
            line: $line,
        );
    }

    // =========================================================================
    // COMPOUND LIST
    // =========================================================================

    /**
     * @return list<StatementNode>
     */
    private function parseCompoundList(): array
    {
        $statements = [];
        $this->skipSeparators();

        while (! $this->check(
            TokenType::EOF,
            TokenType::THEN,
            TokenType::ELSE,
            TokenType::ELIF,
            TokenType::FI,
            TokenType::DO,
            TokenType::DONE,
            TokenType::ESAC,
            TokenType::RPAREN,
            TokenType::RBRACE,
            TokenType::DSEMI,
            TokenType::SEMI_AND,
            TokenType::SEMI_SEMI_AND,
        )) {
            $this->checkIteration();
            $posBefore = $this->pos;

            $stmt = $this->parseStatement();

            if ($stmt instanceof \BashBox\Ast\StatementNode) {
                $statements[] = $stmt;
            }

            $this->skipSeparators();

            if ($this->pos === $posBefore) {
                break;
            }
        }

        return $statements;
    }

    /**
     * @return list<RedirectionNode>
     */
    private function parseTrailingRedirections(): array
    {
        $redirections = [];

        while ($this->isRedirectionToken() || ($this->check(TokenType::NUMBER) && $this->isRedirectionAfterNumber())) {
            $redirections[] = $this->parseRedirection();
        }

        return $redirections;
    }

    // =========================================================================
    // CONDITIONAL EXPRESSIONS ([[ ]])
    // =========================================================================

    private function parseConditionalExpression(): ConditionalExpressionNode
    {
        return $this->parseCondOr();
    }

    private function parseCondOr(): ConditionalExpressionNode
    {
        $left = $this->parseCondAnd();

        while ($this->check(TokenType::OR_OR)) {
            $this->advance();
            $right = $this->parseCondAnd();
            $left = new CondOrNode($left, $right);
        }

        return $left;
    }

    private function parseCondAnd(): ConditionalExpressionNode
    {
        $left = $this->parseCondPrimary();

        while ($this->check(TokenType::AND_AND)) {
            $this->advance();
            $right = $this->parseCondPrimary();
            $left = new CondAndNode($left, $right);
        }

        return $left;
    }

    private function parseCondPrimary(): ConditionalExpressionNode
    {
        // Negation
        if ($this->check(TokenType::BANG)) {
            $this->advance();

            return new CondNotNode($this->parseCondPrimary());
        }

        // Grouping
        if ($this->check(TokenType::LPAREN)) {
            $this->advance();
            $expr = $this->parseConditionalExpression();
            $this->expect(TokenType::RPAREN);

            return new CondGroupNode($expr);
        }

        // Unary operators
        if ($this->isCondUnaryOperator()) {
            $op = $this->advance()->value;
            $operand = $this->parseWord();

            return new CondUnaryNode($op, $operand);
        }

        // Read left operand
        $left = $this->parseWord();

        // Check for binary operator
        if ($this->isCondBinaryOperator()) {
            $op = $this->advance()->value;
            $right = $this->parseWord();

            return new CondBinaryNode($op, $left, $right);
        }

        return new CondWordNode($left);
    }

    private function isCondUnaryOperator(): bool
    {
        $value = $this->current()->value;

        return in_array($value, [
            '-a', '-b', '-c', '-d', '-e', '-f', '-g', '-h', '-k', '-p',
            '-r', '-s', '-t', '-u', '-w', '-x', '-G', '-L', '-N', '-O',
            '-S', '-z', '-n', '-o', '-v', '-R',
        ], true);
    }

    private function isCondBinaryOperator(): bool
    {
        $value = $this->current()->value;

        return in_array($value, [
            '=', '==', '!=', '=~', '<', '>',
            '-eq', '-ne', '-lt', '-le', '-gt', '-ge',
            '-nt', '-ot', '-ef',
        ], true);
    }

    // =========================================================================
    // ARITHMETIC EXPRESSIONS
    // =========================================================================

    /**
     * @param  list<Token>  $tokens
     */
    private function tokensToArithExpr(array $tokens): ArithmeticExpressionNode
    {
        $text = implode('', array_map(fn (Token $t): string => $t->value, $tokens));

        $expr = $this->parseArithExpression($text);

        return new ArithmeticExpressionNode(
            expression: $expr,
            originalText: $text,
        );
    }

    private function parseArithExpression(string $text): \BashBox\Ast\Arithmetic\ArithExpr
    {
        $parser = new ArithmeticParser($text);

        return $parser->parse();
    }
}
