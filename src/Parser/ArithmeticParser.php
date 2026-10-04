<?php

declare(strict_types=1);

namespace BashBox\Parser;

use BashBox\Ast\Arithmetic\ArithArrayElementNode;
use BashBox\Ast\Arithmetic\ArithAssignmentNode;
use BashBox\Ast\Arithmetic\ArithBinaryNode;
use BashBox\Ast\Arithmetic\ArithExpr;
use BashBox\Ast\Arithmetic\ArithGroupNode;
use BashBox\Ast\Arithmetic\ArithNumberNode;
use BashBox\Ast\Arithmetic\ArithTernaryNode;
use BashBox\Ast\Arithmetic\ArithUnaryNode;
use BashBox\Ast\Arithmetic\ArithVariableNode;
use BashBox\Exceptions\ArithmeticException;
use BashBox\Limits;

final class ArithmeticParser
{
    private const string DIGITS = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ@_';

    private const array ASSIGN_OPS = ['<<=', '>>=', '+=', '-=', '*=', '/=', '%=', '&=', '|=', '^=', '='];

    private readonly string $input;

    private int $pos = 0;

    /** Where the last token read starts, for error messages */
    private int $tokenStart = 0;

    private readonly int $len;

    /** How deeply the operand being parsed is nested. */
    private int $depth = 0;

    public function __construct(string $input, private readonly Limits $limits = new Limits)
    {
        // bash quotes the expression in errors without its leading blanks, but with its trailing ones
        $this->input = ltrim($input);
        $this->len = strlen($this->input);
    }

    public function parse(): ArithExpr
    {
        if ($this->len === 0) {
            return new ArithNumberNode(0);
        }

        $arithExpr = $this->parseComma();
        $this->skipWhitespace();

        if ($this->pos < $this->len) {
            throw $this->error('arithmetic syntax error in expression');
        }

        return $arithExpr;
    }

    private function parseComma(): ArithExpr
    {
        $left = $this->parseAssignment();

        while ($this->matchOp(',')) {
            $left = new ArithBinaryNode(',', $left, $this->parseAssignment());
        }

        return $left;
    }

    private function parseAssignment(): ArithExpr
    {
        $arithExpr = $this->parseTernary();

        if ($arithExpr instanceof ArithVariableNode || $arithExpr instanceof ArithArrayElementNode) {
            foreach (self::ASSIGN_OPS as $assignOp) {
                if ($this->matchOp($assignOp)) {
                    $divisor = $this->divisorStart();
                    $arithExpr = new ArithAssignmentNode($assignOp, $arithExpr, $this->nested($this->parseAssignment(...)), $this->divisionError($assignOp, $divisor));

                    break;
                }
            }
        }

        return $arithExpr;
    }

    /**
     * Parse a nested operand, counting parentheses and chained operators against the depth limit.
     *
     * @param  callable(): ArithExpr  $parse
     */
    private function nested(callable $parse): ArithExpr
    {
        if (++$this->depth > $this->limits->maxAstDepth) {
            throw $this->error('expression recursion level exceeded');
        }

        $arithExpr = $parse();
        $this->depth--;

        return $arithExpr;
    }

    private function parseTernary(): ArithExpr
    {
        $arithExpr = $this->parseBinary(0);

        if (! $this->matchOp('?')) {
            return $arithExpr;
        }

        $consequent = $this->nested($this->parseAssignment(...));

        if (! $this->matchOp(':')) {
            throw $this->error("`:' expected for conditional expression");
        }

        return new ArithTernaryNode($arithExpr, $consequent, $this->nested($this->parseAssignment(...)));
    }

    /**
     * Binary operators from lowest to highest precedence. Each operator is
     * listed with the longer operators it must not be mistaken for.
     *
     * @var list<array<string, list<string>>>
     */
    private const array BINARY_LEVELS = [
        ['||' => []],
        ['&&' => []],
        ['|' => ['||', '|=']],
        ['^' => ['^=']],
        ['&' => ['&&', '&=']],
        ['==' => [], '!=' => []],
        ['<=' => [], '>=' => [], '<' => ['<<'], '>' => ['>>']],
        ['<<' => ['<<='], '>>' => ['>>=']],
        ['+' => ['++', '+='], '-' => ['--', '-=']],
        ['*' => ['**', '*='], '/' => ['/='], '%' => ['%=']],
    ];

    private function parseBinary(int $level): ArithExpr
    {
        $ops = self::BINARY_LEVELS[$level] ?? null;

        if ($ops === null) {
            return $this->parseExponentiation();
        }

        $left = $this->parseBinary($level + 1);

        while (($op = $this->matchAnyOp($ops)) !== null) {
            $divisor = $this->divisorStart();
            $left = new ArithBinaryNode($op, $left, $this->parseBinary($level + 1), $this->divisionError($op, $divisor));
        }

        return $left;
    }

    private function parseExponentiation(): ArithExpr
    {
        $arithExpr = $this->parseUnary();

        if ($this->matchOp('**')) {
            // Right-associative.
            $exponent = $this->nested($this->parseExponentiation(...));

            return new ArithBinaryNode('**', $arithExpr, $exponent, $this->runtimeError('exponent less than 0', $this->token()));
        }

        return $arithExpr;
    }

    private function parseUnary(): ArithExpr
    {
        $op = $this->matchAnyOp(['++' => [], '--' => [], '+' => [], '-' => [], '!' => ['!='], '~' => []]);

        if ($op !== null) {
            return new ArithUnaryNode($op, $this->nested($this->parseUnary(...)), true);
        }

        $arithExpr = $this->parsePrimary();

        if (($arithExpr instanceof ArithVariableNode || $arithExpr instanceof ArithArrayElementNode) && ($op = $this->matchAnyOp(['++' => [], '--' => []])) !== null) {
            return new ArithUnaryNode($op, $arithExpr, false);
        }

        return $arithExpr;
    }

    private function parsePrimary(): ArithExpr
    {
        $this->skipWhitespace();
        $ch = $this->input[$this->pos] ?? '';

        if ($ch === '(') {
            $this->tokenStart = $this->pos++;
            $expr = $this->nested($this->parseComma(...));

            if (! $this->matchOp(')')) {
                throw $this->error("missing `)'");
            }

            return new ArithGroupNode($expr);
        }

        if (ctype_digit($ch)) {
            return $this->parseNumber();
        }

        if (preg_match('/\G[a-zA-Z_]\w*/', $this->input, $m, 0, $this->pos) === 1) {
            $start = $this->pos;
            $this->tokenStart = $this->pos;
            $this->pos += strlen($m[0]);

            return ($this->input[$this->pos] ?? '') === '[' ? $this->parseElement($m[0], $start) : new ArithVariableNode($m[0]);
        }

        throw $this->error('arithmetic syntax error: operand expected');
    }

    /** `name[subscript]`, with $pos on the `[`; subscripts may nest, as in a[b[1]]. */
    private function parseElement(string $name, int $start): ArithArrayElementNode
    {
        $depth = 0;

        for ($end = $this->pos; $end < $this->len; $end++) {
            $depth += match ($this->input[$end]) {
                '[' => 1,
                ']' => -1,
                default => 0,
            };

            if ($depth === 0) {
                break;
            }
        }

        if ($end === $this->len) {
            throw new ArithmeticException($this->runtimeError('bad array subscript', substr($this->input, $start)));
        }

        $subscript = substr($this->input, $this->pos + 1, $end - $this->pos - 1);
        $this->pos = $end + 1;

        return new ArithArrayElementNode($name, $subscript);
    }

    /**
     * Integer constants: decimal, 0-prefixed octal, 0x hex and base#digits (base 2-64).
     */
    private function parseNumber(): ArithNumberNode
    {
        $start = $this->pos;
        $this->pos += strspn($this->input, self::DIGITS.'#', $this->pos);
        $text = substr($this->input, $start, $this->pos - $start);
        $this->pos = $start;

        if (str_contains($text, '#')) {
            if (preg_match('/^(\d+)#(.+)$/', $text, $m) !== 1) {
                throw $this->numberError('invalid integer constant', $start, $text);
            }

            [, $base, $digits] = $m;
            $base = (int) $base;

            if ($base < 2 || $base > 64) {
                throw $this->numberError('invalid arithmetic base', $start, $text);
            }
        } elseif (preg_match('/^0[xX]/', $text) === 1) {
            [$base, $digits] = [16, substr($text, 2)];
        } else {
            [$base, $digits] = [$text[0] === '0' ? 8 : 10, $text];
        }

        if ($base <= 36) {
            $digits = strtolower($digits);
        }

        $value = 0;

        foreach (str_split($digits) as $digit) {
            $digitValue = strpos(self::DIGITS, $digit);

            if ($digitValue === false) {
                throw $this->numberError('invalid number', $start, $text);
            }

            if ($digitValue >= $base) {
                throw $this->numberError('value too great for base', $start, $text);
            }

            $value = Int64::add(Int64::mul($value, $base), $digitValue);
        }

        $this->tokenStart = $start;
        $this->pos += strlen($text);

        return new ArithNumberNode($value);
    }

    /** bash checks a number on its own, so the message quotes the expression only as far as the number */
    private function numberError(string $message, int $start, string $text): ArithmeticException
    {
        return new ArithmeticException(sprintf('%s: %s (error token is "%s")', substr($this->input, 0, $start + strlen($text)), $message, $text));
    }

    private function skipWhitespace(): void
    {
        $this->pos += strspn($this->input, " \t\n", $this->pos);
    }

    /**
     * Consume $op unless the input actually holds one of the longer operators in $longer.
     */
    private function matchOp(string $op, string ...$longer): bool
    {
        $this->skipWhitespace();

        foreach ([$op, ...$longer] as $i => $candidate) {
            if (substr($this->input, $this->pos, strlen($candidate)) === $candidate) {
                if ($i > 0) {
                    return false;
                }

                continue;
            }

            if ($i === 0) {
                return false;
            }
        }

        $this->tokenStart = $this->pos;
        $this->pos += strlen($op);

        return true;
    }

    /**
     * @param  array<string, list<string>>  $ops
     */
    private function matchAnyOp(array $ops): ?string
    {
        foreach ($ops as $op => $longer) {
            if ($this->matchOp($op, ...$longer)) {
                return $op;
            }
        }

        return null;
    }

    /** Where the operand after a `/`, `%` (or `/=`, `%=`) starts: bash's token for division by 0 */
    private function divisorStart(): int
    {
        $this->skipWhitespace();

        return $this->pos;
    }

    /**
     * The error token, as bash reports it: from the token it has just looked ahead to, or at the end of the
     * input from the last token read, to the end.
     */
    private function token(): string
    {
        $this->skipWhitespace();

        return substr($this->input, $this->pos < $this->len ? $this->pos : $this->tokenStart);
    }

    /** For `/`, `%`, `/=` and `%=`: the message should the operand starting at $divisor be 0 */
    private function divisionError(string $op, int $divisor): string
    {
        return in_array($op, ['/', '%', '/=', '%='], true) ? $this->runtimeError('division by 0', substr($this->input, $divisor)) : '';
    }

    /** A message for an error found while evaluating, kept on the node it belongs to */
    private function runtimeError(string $message, string $token): string
    {
        return sprintf('%s: %s (error token is "%s")', $this->input, $message, $token);
    }

    private function error(string $message): ArithmeticException
    {
        return new ArithmeticException($this->runtimeError($message, $this->token()));
    }
}
