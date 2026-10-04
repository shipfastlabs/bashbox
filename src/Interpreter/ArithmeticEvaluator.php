<?php

declare(strict_types=1);

namespace BashBox\Interpreter;

use BashBox\Ast\Arithmetic\ArithArrayElementNode;
use BashBox\Ast\Arithmetic\ArithAssignmentNode;
use BashBox\Ast\Arithmetic\ArithBinaryNode;
use BashBox\Ast\Arithmetic\ArithExpr;
use BashBox\Ast\Arithmetic\ArithGroupNode;
use BashBox\Ast\Arithmetic\ArithNumberNode;
use BashBox\Ast\Arithmetic\ArithTernaryNode;
use BashBox\Ast\Arithmetic\ArithUnaryNode;
use BashBox\Ast\Arithmetic\ArithVariableNode;
use BashBox\Ast\ArithmeticExpressionNode;
use BashBox\Ast\Parts\LiteralPart;
use BashBox\Ast\WordNode;
use BashBox\Exceptions\ArithmeticException;
use BashBox\Exceptions\ExitException;
use BashBox\Parser\ArithmeticParser;
use BashBox\Parser\Int64;

/** Evaluates `$((...))`, `((...))`, `let` and array subscripts over 64-bit integers that wrap like bash's. */
final readonly class ArithmeticEvaluator
{
    public function __construct(
        private InterpreterState $interpreterState,
        private Interpreter $interpreter,
    ) {}

    public function evaluateExpression(ArithmeticExpressionNode $arithmeticExpressionNode): int
    {
        // Re-evaluated from the source text so `$x` expands textually, as in bash
        return $this->evaluateString($arithmeticExpressionNode->originalText);
    }

    /** Expands the text (parameters, command substitution, quote removal) and then evaluates it, as bash does. */
    public function evaluateString(string $expr): int
    {
        return $this->evaluateText($this->interpreter->expandWord(new WordNode([new LiteralPart($expr)])));
    }

    /** Evaluates text that is already expanded: a `$` or quote in it is an error, never run. */
    public function evaluateText(string $expr): int
    {
        return $this->evaluate(new ArithmeticParser($expr, $this->interpreterState->limits)->parse());
    }

    /** Evaluates an -i value or an array subscript, already expanded: one that doesn't evaluate ends the shell, as in bash. */
    public function evaluateOrExit(string $expression, string $builtin = ''): int
    {
        try {
            return $this->evaluateText($expression);
        } catch (ArithmeticException $arithmeticException) {
            $this->interpreter->writeStderr("bash: {$builtin}{$arithmeticException->getMessage()}\n");

            throw new ExitException(1);
        }
    }

    /** An associative array's key is the subscript as written; an indexed one's is its value, negative counting back from the end. */
    private function elementKey(ArithArrayElementNode $arithArrayElementNode): int|string
    {
        $array = $this->interpreterState->getArray($arithArrayElementNode->name);

        if (array_filter(array_keys($array), is_string(...)) !== []) {
            return $arithArrayElementNode->subscript;
        }

        $index = $this->evaluateString($arithArrayElementNode->subscript);

        return $index < 0 && $array !== [] ? $index + (int) max(array_keys($array)) + 1 : $index;
    }

    private function read(ArithVariableNode|ArithArrayElementNode $node): string
    {
        $name = $node->name;

        // bash reports `a[]` twice and reads it as 0
        if ($node instanceof ArithArrayElementNode && $node->subscript === '') {
            $this->interpreter->writeStderr(str_repeat("bash: {$name}[]: bad array subscript\n", 2));

            return '0';
        }

        if ($node instanceof ArithArrayElementNode) {
            $key = $this->elementKey($node);

            return $this->interpreterState->getArray($name)[$key] ?? '0';
        }

        return $this->interpreterState->getVar($name) ?? $this->interpreterState->getSpecialVar($name) ?? '0';
    }

    private function write(ArithVariableNode|ArithArrayElementNode $node, int $value): int
    {
        if ($node instanceof ArithArrayElementNode) {
            $this->interpreterState->setElement($node->name, $this->elementKey($node), (string) $value);
        } else {
            $this->interpreterState->setVar($node->name, (string) $value);
        }

        return $value;
    }

    private function evaluate(ArithExpr $arithExpr): int
    {
        if ($arithExpr instanceof ArithNumberNode) {
            return $arithExpr->value;
        }

        if ($arithExpr instanceof ArithVariableNode || $arithExpr instanceof ArithArrayElementNode) {
            $val = $this->read($arithExpr);

            // A value that isn't a plain decimal is itself an expression, as in bash: `010` is octal, `08` an error
            if ($val !== '' && preg_match('/^-?(?:0|[1-9]\d{0,17})$/', $val) !== 1) {
                return $this->evaluateText($val);
            }

            return (int) $val;
        }

        if ($arithExpr instanceof ArithBinaryNode) {
            $left = $this->evaluate($arithExpr->left);

            // Short-circuit like bash: the right side's side effects only happen when it's needed
            if ($arithExpr->operator === '&&') {
                return (int) ($left !== 0 && $this->evaluate($arithExpr->right) !== 0);
            }

            if ($arithExpr->operator === '||') {
                return (int) ($left !== 0 || $this->evaluate($arithExpr->right) !== 0);
            }

            return $this->operate($arithExpr->operator, $left, $this->evaluate($arithExpr->right), $arithExpr->error);
        }

        if ($arithExpr instanceof ArithUnaryNode) {
            if (($arithExpr->operator === '++' || $arithExpr->operator === '--') && ($arithExpr->operand instanceof ArithVariableNode || $arithExpr->operand instanceof ArithArrayElementNode)) {
                $old = $this->evaluate($arithExpr->operand);
                $new = $this->write($arithExpr->operand, Int64::add($old, $arithExpr->operator === '++' ? 1 : -1));

                return $arithExpr->prefix ? $new : $old;
            }

            $operand = $this->evaluate($arithExpr->operand);

            return match ($arithExpr->operator) {
                '-' => Int64::sub(0, $operand),
                '+' => $operand,
                '!' => $operand === 0 ? 1 : 0,
                '~' => ~$operand,
                default => $operand,
            };
        }

        if ($arithExpr instanceof ArithTernaryNode) {
            $cond = $this->evaluate($arithExpr->condition);

            return $cond !== 0
                ? $this->evaluate($arithExpr->consequent)
                : $this->evaluate($arithExpr->alternate);
        }

        if ($arithExpr instanceof ArithAssignmentNode) {
            $value = $this->evaluate($arithExpr->value);
            $current = $arithExpr->operator === '=' ? 0 : $this->evaluate($arithExpr->target);

            return $this->write($arithExpr->target, $arithExpr->operator === '=' ? $value : $this->operate(substr($arithExpr->operator, 0, -1), $current, $value, $arithExpr->error));
        }

        assert($arithExpr instanceof ArithGroupNode);

        return $this->evaluate($arithExpr->expression);
    }

    /** A binary operator with bash's wrap-around and mod-64 shifts; $error is the message for division by 0 or a negative exponent. */
    private function operate(string $operator, int $left, int $right, string $error): int
    {
        if (($right === 0 && ($operator === '/' || $operator === '%')) || ($right < 0 && $operator === '**')) {
            throw new ArithmeticException($error);
        }

        return match ($operator) {
            '+' => Int64::add($left, $right),
            '-' => Int64::sub($left, $right),
            '*' => Int64::mul($left, $right),
            // PHP_INT_MIN / -1 doesn't fit: it wraps back to PHP_INT_MIN
            '/' => $right === -1 ? Int64::sub(0, $left) : intdiv($left, $right),
            '%' => $right === -1 ? 0 : $left % $right,
            '**' => Int64::pow($left, $right),
            '<<' => $left << ($right & 63),
            '>>' => $left >> ($right & 63),
            '<' => (int) ($left < $right),
            '<=' => (int) ($left <= $right),
            '>' => (int) ($left > $right),
            '>=' => (int) ($left >= $right),
            '==' => (int) ($left === $right),
            '!=' => (int) ($left !== $right),
            '&' => $left & $right,
            '|' => $left | $right,
            '^' => $left ^ $right,
            default => $right, // ','
        };
    }
}
