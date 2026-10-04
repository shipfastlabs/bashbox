<?php

declare(strict_types=1);

namespace BashBox\Interpreter;

use BashBox\Ast\Conditional\CondAndNode;
use BashBox\Ast\Conditional\CondBinaryNode;
use BashBox\Ast\Conditional\CondGroupNode;
use BashBox\Ast\Conditional\ConditionalExpressionNode;
use BashBox\Ast\Conditional\CondNotNode;
use BashBox\Ast\Conditional\CondOrNode;
use BashBox\Ast\Conditional\CondUnaryNode;
use BashBox\Ast\Conditional\CondWordNode;
use BashBox\Ast\WordNode;
use BashBox\Filesystem\FsStat;
use BashBox\Interpreter\Expansion\Glob;
use BashBox\Interpreter\Expansion\WordExpander;
use BashBox\Regex\PosixRegex;
use BashBox\Regex\RegexException;
use BashBox\Regex\SafePcreRegex;

/** Evaluates `[[ ... ]]` expressions and the glob matching `case` shares with them. */
final readonly class ConditionalEvaluator
{
    public function __construct(
        private InterpreterState $interpreterState,
        private WordExpander $wordExpander,
        private ArithmeticEvaluator $arithmeticEvaluator,
        private Interpreter $interpreter,
    ) {}

    /** @throws RegexException for a regex that doesn't compile or a match that runs out of backtracking */
    public function evaluate(ConditionalExpressionNode $conditionalExpressionNode): bool
    {
        if ($conditionalExpressionNode instanceof CondBinaryNode) {
            $left = $this->wordExpander->expand($conditionalExpressionNode->left);
            $operator = $conditionalExpressionNode->operator;

            if (in_array($operator, ['=', '==', '!='], true)) {
                return $this->matchPattern($left, $conditionalExpressionNode->right, extglob: true) === ($operator !== '!=');
            }

            if ($operator === '=~') {
                // Quoted parts of the regex match literally
                return $this->matchRegex($left, $this->wordExpander->expandPattern($conditionalExpressionNode->right, regex: true));
            }

            $right = $this->wordExpander->expand($conditionalExpressionNode->right);
            // [[ ]] evaluates integer operands as arithmetic: `010` is 8, `1+1` is 2
            $int = $this->arithmeticEvaluator->evaluateText(...);

            return match ($operator) {
                '<' => strcmp($left, $right) < 0,
                '>' => strcmp($left, $right) > 0,
                '-eq' => $int($left) === $int($right),
                '-ne' => $int($left) !== $int($right),
                '-lt' => $int($left) < $int($right),
                '-le' => $int($left) <= $int($right),
                '-gt' => $int($left) > $int($right),
                '-ge' => $int($left) >= $int($right),
                // A missing file counts as older than any existing one
                '-nt' => ($this->interpreter->statPath($left)->mtime ?? PHP_INT_MIN) > ($this->interpreter->statPath($right)->mtime ?? PHP_INT_MIN),
                '-ot' => ($this->interpreter->statPath($left)->mtime ?? PHP_INT_MIN) < ($this->interpreter->statPath($right)->mtime ?? PHP_INT_MIN),
                default => $this->interpreter->realPath($left) !== null && $this->interpreter->realPath($left) === $this->interpreter->realPath($right), // -ef
            };
        }

        if ($conditionalExpressionNode instanceof CondUnaryNode) {
            $operand = $this->wordExpander->expand($conditionalExpressionNode->operand);

            return match ($conditionalExpressionNode->operator) {
                '-z' => $operand === '',
                '-n' => $operand !== '',
                '-v' => $this->interpreterState->getVar($operand) !== null,
                '-a', '-e', '-r', '-w' => $this->interpreter->statPath($operand) instanceof FsStat,
                '-f' => $this->interpreter->statPath($operand)->isFile ?? false,
                '-d' => $this->interpreter->statPath($operand)->isDirectory ?? false,
                '-s' => ($this->interpreter->statPath($operand)->size ?? 0) > 0,
                '-x' => (($this->interpreter->statPath($operand)->mode ?? 0) & 0o111) !== 0,
                '-L', '-h' => $this->interpreter->statPath($operand, followLinks: false)->isSymbolicLink ?? false,
                // the sandbox has no devices, pipes, sockets, ttys or special mode bits
                default => false,
            };
        }

        if ($conditionalExpressionNode instanceof CondNotNode) {
            return ! $this->evaluate($conditionalExpressionNode->operand);
        }

        if ($conditionalExpressionNode instanceof CondAndNode) {
            return $this->evaluate($conditionalExpressionNode->left) && $this->evaluate($conditionalExpressionNode->right);
        }

        if ($conditionalExpressionNode instanceof CondOrNode) {
            if ($this->evaluate($conditionalExpressionNode->left)) {
                return true;
            }

            return $this->evaluate($conditionalExpressionNode->right);
        }

        if ($conditionalExpressionNode instanceof CondGroupNode) {
            return $this->evaluate($conditionalExpressionNode->expression);
        }

        // A lone word: true when non-empty
        assert($conditionalExpressionNode instanceof CondWordNode);

        return $this->wordExpander->expand($conditionalExpressionNode->word) !== '';
    }

    /** Glob match as in `case` and `[[ == ]]`, honouring nocasematch; `[[ ]]` always understands extglob, `case` only with `shopt -s extglob`. */
    public function matchPattern(string $str, WordNode $wordNode, bool $extglob = false): bool
    {
        return Glob::matches(
            $this->wordExpander->expandPattern($wordNode),
            $str,
            $extglob || $this->interpreterState->shopt['extglob'],
            $this->interpreterState->shopt['nocasematch'],
        );
    }

    /** `[[ str =~ regex ]]`, filling BASH_REMATCH like bash. */
    private function matchRegex(string $subject, string $regex): bool
    {
        $pcre = "\x01{$regex}\x01".(Glob::flags($regex.$subject) === 'su' ? 'u' : '').($this->interpreterState->shopt['nocasematch'] ? 'i' : '');
        $error = PosixRegex::error($pcre);

        if ($error !== null) {
            throw new RegexException(sprintf("invalid regular expression `%s': %s", $regex, $error));
        }

        if (! SafePcreRegex::match($pcre, $subject, $matches)) {
            return false;
        }

        $this->interpreterState->arrays['BASH_REMATCH'] = array_filter($matches, is_string(...));

        return true;
    }
}
