<?php

declare(strict_types=1);

namespace BashBox\Ast;

use BashBox\Ast\Conditional\CondAndNode;
use BashBox\Ast\Conditional\CondBinaryNode;
use BashBox\Ast\Conditional\CondGroupNode;
use BashBox\Ast\Conditional\ConditionalExpressionNode;
use BashBox\Ast\Conditional\CondNotNode;
use BashBox\Ast\Conditional\CondOrNode;
use BashBox\Ast\Conditional\CondUnaryNode;
use BashBox\Ast\Conditional\CondWordNode;
use BashBox\Parser\Lexer;
use Closure;

/** Prints a function the way `type` and `declare -f` do, as a port of bash's print_cmd.c so the layout matches. */
final class FunctionPrinter
{
    private string $out = '';

    private int $indentation = 0;

    private int $skipIndent = 0;

    private int $printingConnection = 0;

    /** @var list<RedirectionNode> here-documents whose bodies wait for the connector after their command */
    private array $deferred = [];

    private bool $wasHeredoc = false;

    public static function print(string $name, CompoundCommandNode $compoundCommandNode): string
    {
        $printer = new self;
        $printer->out = $name." () \n{ \n";
        $printer->indentation = 4;
        $printer->functionBody($compoundCommandNode);

        return $printer->out;
    }

    /** A simple command as source text, here-document bodies included */
    public static function simpleCommandText(SimpleCommandNode $simpleCommandNode): string
    {
        $printer = new self;
        $printer->simpleCommand($simpleCommandNode, []);

        return $printer->out;
    }

    /** A function body without its braces, then the closing brace and the group's redirections */
    private function functionBody(CompoundCommandNode $compoundCommandNode): void
    {
        if ($compoundCommandNode instanceof GroupNode) {
            $this->statements($compoundCommandNode->body);
        } else {
            $this->command($compoundCommandNode);
        }

        $this->printDeferred('');
        $this->indentation -= 4;
        $this->newline('}');

        if ($compoundCommandNode instanceof GroupNode && $compoundCommandNode->redirections !== []) {
            $this->out .= ' ';
            $this->redirections($compoundCommandNode->redirections);
        }
    }

    /** @param list<StatementNode> $statements */
    private function statements(array $statements): void
    {
        $items = [];
        $ops = [];

        foreach ($statements as $statement) {
            $items[] = fn (string $prefix) => $this->statement($statement, $prefix);
            $ops[] = $statement->background ? '&' : ';';
        }

        // A trailing `;` is just the end of the list; a trailing `&` stays.
        if (end($ops) === ';') {
            array_pop($ops);
        }

        $this->chain($items, $ops);
    }

    private function statement(StatementNode $statementNode, string $prefix): void
    {
        $this->chain(
            array_map(fn (PipelineNode $pipelineNode): Closure => fn (string $prefix) => $this->pipeline($pipelineNode, $prefix), $statementNode->pipelines),
            $statementNode->operators,
            $prefix,
        );
    }

    private function pipeline(PipelineNode $pipelineNode, string $prefix): void
    {
        $prefix .= ($pipelineNode->timed ? 'time '.($pipelineNode->timePosix ? '-p ' : '') : '').($pipelineNode->negated ? '! ' : '');
        $items = [];

        foreach ($pipelineNode->commands as $i => $command) {
            // `a |& b` is `a 2>&1 | b`.
            $extra = ($pipelineNode->pipeStderr[$i] ?? false) ? [new RedirectionNode('>&', new WordNode([new Parts\LiteralPart('1')]), 2)] : [];
            $items[] = fn (string $prefix) => $this->command($command, $prefix, $extra);
        }

        $this->chain($items, array_fill(0, count($items) - 1, '|'), $prefix);
    }

    /**
     * Items joined by connectors, nested to the left like bash's parse tree; only the chain's start is indented.
     *
     * @param  list<Closure(string): void>  $items
     * @param  list<string>  $ops  one fewer than the items, or as many for a trailing `&`
     */
    private function chain(array $items, array $ops, string $prefix = ''): void
    {
        if ($ops === []) {
            if ($items !== []) {
                $items[0]($prefix);
            }

            return;
        }

        $this->indent();
        $this->out .= $prefix;
        $this->printingConnection++;
        $this->skipIndent++;
        $items[0]('');

        foreach ($ops as $i => $op) {
            $second = $items[$i + 1] ?? null;
            $this->connector($op, $second !== null);

            if ($second !== null) {
                $second('');
            }

            $this->printDeferred('');
        }

        $this->printingConnection--;
    }

    private function connector(string $op, bool $hasSecond): void
    {
        if ($op === ';') {
            if ($this->deferred !== []) {
                $this->printDeferred('');
            } elseif ($this->wasHeredoc) {
                $this->wasHeredoc = false;
            } else {
                $this->out .= ';';
            }

            // Always inside a function definition: one command per line.
            $this->out .= "\n";

            return;
        }

        $this->printDeferred($op === '&' || $op === '|' ? ' '.$op : ' '.$op.' ');

        if ($hasSecond) {
            $this->out .= $op === '&' || $op === '|' ? ' ' : '';
            $this->skipIndent++;
        }
    }

    /**
     * @param  list<RedirectionNode>  $extra  added after the command's own redirections
     */
    private function command(CommandNode $commandNode, string $prefix = '', array $extra = []): void
    {
        $this->indent();
        $this->out .= $prefix;

        if ($commandNode instanceof SimpleCommandNode) {
            $this->simpleCommand($commandNode, $extra);

            return;
        }

        if ($commandNode instanceof FunctionDefNode) {
            $this->out .= 'function '.$commandNode->name." () \n";
            $this->indent();
            $this->out .= "{ \n";
            $this->indentation += 4;
            $this->functionBody($commandNode->body);

            return;
        }

        assert($commandNode instanceof CompoundCommandNode);

        // The parser builds only these compound commands.
        match (true) { // @phpstan-ignore match.unhandled
            $commandNode instanceof ForNode => $this->forCommand($commandNode),
            $commandNode instanceof CStyleForNode => $this->cStyleFor($commandNode),
            $commandNode instanceof WhileNode, $commandNode instanceof UntilNode => $this->loop($commandNode),
            $commandNode instanceof IfNode => $this->ifCommand($commandNode->clauses, $commandNode->elseBody),
            $commandNode instanceof CaseNode => $this->caseCommand($commandNode),
            $commandNode instanceof SubshellNode => $this->subshell($commandNode),
            $commandNode instanceof GroupNode => $this->group($commandNode),
            $commandNode instanceof ArithmeticCommandNode => $this->out .= '(('.$commandNode->expression->originalText.'))',
            $commandNode instanceof ConditionalCommandNode => $this->out .= '[[ '.$this->condition($commandNode->expression).' ]]',
        };

        $redirections = [...$commandNode->redirections, ...$extra];

        if ($redirections !== []) {
            $this->out .= ' ';
            $this->redirections($redirections);
        }
    }

    /** @param list<RedirectionNode> $extra */
    private function simpleCommand(SimpleCommandNode $simpleCommandNode, array $extra): void
    {
        $words = array_map($this->assignment(...), $simpleCommandNode->assignments);

        if ($simpleCommandNode->name instanceof WordNode) {
            $words[] = $this->word($simpleCommandNode->name);
        }

        foreach ($simpleCommandNode->args as $i => $arg) {
            $words[] = isset($simpleCommandNode->arrayArgs[$i]) ? $this->assignment($simpleCommandNode->arrayArgs[$i]) : $this->word($arg);
        }

        $this->out .= implode(' ', $words);
        $redirections = [...$simpleCommandNode->redirections, ...$extra];

        if ($redirections !== []) {
            $this->out .= $words === [] ? '' : ' ';
            $this->redirections($redirections);
        }
    }

    private function forCommand(ForNode $forNode): void
    {
        $words = $forNode->words === null ? '"$@"' : implode(' ', array_map($this->word(...), $forNode->words));
        $this->out .= sprintf('for %s in %s;', $forNode->variable, $words);
        $this->loopBody($forNode->body);
    }

    private function cStyleFor(CStyleForNode $cStyleForNode): void
    {
        // An empty part reads as 1.
        $parts = array_map(fn (?ArithmeticExpressionNode $arithmeticExpressionNode): string => $arithmeticExpressionNode->originalText ?? '1', [$cStyleForNode->init, $cStyleForNode->condition, $cStyleForNode->update]);
        $this->out .= 'for (('.implode('; ', $parts).'))';
        $this->loopBody($cStyleForNode->body);
    }

    /** @param list<StatementNode> $body */
    private function loopBody(array $body): void
    {
        $this->newline("do\n");
        $this->indentation += 4;
        $this->statements($body);
        $this->printDeferred('');
        $this->semicolon();
        $this->indentation -= 4;
        $this->newline('done');
    }

    private function loop(WhileNode|UntilNode $loop): void
    {
        $this->out .= $loop instanceof WhileNode ? 'while ' : 'until ';
        $this->skipIndent++;
        $this->statements($loop->condition);
        $this->printDeferred('');
        $this->semicolon();
        $this->out .= " do\n";
        $this->indentation += 4;
        $this->statements($loop->body);
        $this->printDeferred('');
        $this->indentation -= 4;
        $this->semicolon();
        $this->newline('done');
    }

    /**
     * `elif` prints as an `if` nested in the `else`, as bash stores it.
     *
     * @param  list<IfClause>  $clauses
     * @param  list<StatementNode>|null  $elseBody
     */
    private function ifCommand(array $clauses, ?array $elseBody): void
    {
        $clause = array_shift($clauses);
        assert($clause instanceof IfClause);
        $this->out .= 'if ';
        $this->skipIndent++;
        $this->statements($clause->condition);
        $this->semicolon();
        $this->out .= " then\n";
        $this->indentation += 4;
        $this->statements($clause->body);
        $this->printDeferred('');
        $this->indentation -= 4;

        if ($clauses !== [] || $elseBody !== null) {
            $this->semicolon();
            $this->newline("else\n");
            $this->indentation += 4;

            if ($clauses !== []) {
                $this->indent();
                $this->ifCommand($clauses, $elseBody);
            } else {
                $this->statements((array) $elseBody);
            }

            $this->printDeferred('');
            $this->indentation -= 4;
        }

        $this->semicolon();
        $this->newline('fi');
    }

    private function caseCommand(CaseNode $caseNode): void
    {
        $this->out .= 'case '.$this->word($caseNode->word).' in ';
        $this->indentation += 4;

        foreach ($caseNode->items as $item) {
            $this->newline(implode(' | ', array_map($this->word(...), $item->patterns)).")\n");
            $this->indentation += 4;
            $this->statements($item->body);
            $this->indentation -= 4;
            $this->printDeferred('');
            $this->newline($item->terminator);
        }

        $this->indentation -= 4;
        $this->newline('esac');
    }

    private function subshell(SubshellNode $subshellNode): void
    {
        $this->out .= '( ';
        $this->skipIndent++;
        $this->statements($subshellNode->body);
        $this->printDeferred('');
        $this->out .= ' )';
    }

    /** Inside a function a brace group always spreads over lines */
    private function group(GroupNode $groupNode): void
    {
        $this->out .= "{ \n";
        $this->indentation += 4;
        $this->statements($groupNode->body);
        $this->printDeferred('');
        $this->out .= "\n";
        $this->indentation -= 4;
        $this->indent();
        $this->out .= '}';
    }

    private function condition(ConditionalExpressionNode $conditionalExpressionNode): string
    {
        return match (true) { // @phpstan-ignore match.unhandled
            $conditionalExpressionNode instanceof CondAndNode => $this->condition($conditionalExpressionNode->left).' && '.$this->condition($conditionalExpressionNode->right),
            $conditionalExpressionNode instanceof CondOrNode => $this->condition($conditionalExpressionNode->left).' || '.$this->condition($conditionalExpressionNode->right),
            $conditionalExpressionNode instanceof CondNotNode => '! '.$this->condition($conditionalExpressionNode->operand),
            $conditionalExpressionNode instanceof CondGroupNode => '( '.$this->condition($conditionalExpressionNode->expression).' )',
            $conditionalExpressionNode instanceof CondUnaryNode => $conditionalExpressionNode->operator.' '.$this->word($conditionalExpressionNode->operand),
            $conditionalExpressionNode instanceof CondBinaryNode => $this->word($conditionalExpressionNode->left).' '.$conditionalExpressionNode->operator.' '.$this->word($conditionalExpressionNode->right),
            // A lone word is a -n test.
            $conditionalExpressionNode instanceof CondWordNode => '-n '.$this->word($conditionalExpressionNode->word),
        };
    }

    /**
     * Here-document bodies follow the redirections, or the connector after the command when it's part of a list.
     *
     * @param  list<RedirectionNode>  $redirections
     */
    private function redirections(array $redirections): void
    {
        $heredocs = [];
        $this->wasHeredoc = false;
        $printed = [];

        foreach ($redirections as $redirection) {
            if ($redirection->target instanceof HereDocNode) {
                $heredocs[] = $redirection;
            }

            $printed[] = $this->redirection($redirection);
        }

        $this->out .= implode(' ', $printed);

        if ($heredocs !== [] && $this->printingConnection > 0) {
            $this->deferred = $heredocs;
        } elseif ($heredocs !== []) {
            $this->heredocBodies($heredocs);
        }
    }

    private function redirection(RedirectionNode $redirectionNode): string
    {
        $fd = $redirectionNode->fd ?? 1;
        $op = $redirectionNode->operator;
        $target = $redirectionNode->target;
        // The fd as written: `{name}` always shows, a number only when it isn't the operator's default.
        $named = $redirectionNode->fdVariable === null ? null : '{'.$redirectionNode->fdVariable.'}';
        $prefix = fn (int $default): string => $named ?? ($fd === $default ? '' : (string) $fd);

        if ($target instanceof HereDocNode) {
            $delimiter = $target->quoted ? "'".str_replace("'", "'\\''", $target->delimiter)."'" : $target->delimiter;

            return $prefix(0).$op.$delimiter;
        }

        $word = $this->word($target);

        return match ($op) {
            '<', '<>', '<<<' => $prefix(0).$op.' '.$word,
            '&>', '&>>' => $op.' '.$word,
            // `>&2` is stored as fd 1; numbers and `-` always show the fd, a word only when it isn't the default.
            '>&', '<&' => $named !== null || preg_match('/^(\d+-?|-)$/', $word) === 1 || $fd !== ($op === '>&' ? 1 : 0)
                ? ($named ?? $fd).($word === '-' ? '>&' : $op).$word
                : $op.$word,
            default => $prefix(1).$op.' '.$word, // > >> >|
        };
    }

    /** @param list<RedirectionNode> $heredocs */
    private function heredocBodies(array $heredocs): void
    {
        $this->out .= "\n";

        foreach ($heredocs as $heredoc) {
            assert($heredoc->target instanceof HereDocNode);
            $body = $this->raw($heredoc->target->content);
            $this->out .= ($heredoc->target->stripTabs ? (string) preg_replace('/^\t+/m', '', $body) : $body).$heredoc->target->delimiter."\n";
        }

        $this->wasHeredoc = true;
    }

    /** bash's print_deferred_heredocs: the connector text, then any bodies waiting for it */
    private function printDeferred(string $text): void
    {
        $this->out .= $text;

        if ($this->deferred !== []) {
            $this->heredocBodies($this->deferred);
            $this->out .= $text === '' ? '' : ' ';
            $this->deferred = [];
        }
    }

    private function assignment(AssignmentNode $assignmentNode): string
    {
        $value = $assignmentNode->array === null
            ? ($assignmentNode->value instanceof WordNode ? $this->word($assignmentNode->value) : '')
            : '('.implode(' ', array_map($this->word(...), $assignmentNode->array)).')';

        return $assignmentNode->name.($assignmentNode->append ? '+=' : '=').$value;
    }

    /** A word as written, except that bash prints $'...' single-quoted with its escapes decoded, and $"..." as "..." */
    private function word(WordNode $wordNode): string
    {
        return (string) preg_replace_callback(
            '/\$\'((?:\\\\.|[^\'\\\\])*)\'|\$("(?:\\\\.|[^"\\\\])*")|"(?:\\\\.|[^"\\\\])*"|\'[^\']*\'|\\\\./s',
            fn (array $m): string => match (true) {
                $m[1] !== null => "'".str_replace("'", "'\\''", Lexer::ansiC($m[1]))."'",
                $m[2] !== null => $m[2],
                default => $m[0],
            },
            $this->raw($wordNode),
            flags: PREG_UNMATCHED_AS_NULL,
        );
    }

    private function raw(WordNode $wordNode): string
    {
        return implode('', array_map(fn (WordPart $wordPart): string => $wordPart instanceof Parts\LiteralPart ? $wordPart->value : '', $wordNode->parts));
    }

    private function indent(): void
    {
        if ($this->skipIndent > 0) {
            $this->skipIndent--;
        } else {
            $this->out .= str_repeat(' ', $this->indentation);
        }
    }

    private function newline(string $text): void
    {
        $this->out .= "\n".str_repeat(' ', $this->indentation).$text;
    }

    /** A `;` unless the text already ends a command with `&` or a newline */
    private function semicolon(): void
    {
        if (! str_ends_with($this->out, '&') && ! str_ends_with($this->out, "\n")) {
            $this->out .= ';';
        }
    }
}
