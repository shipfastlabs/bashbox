<?php

declare(strict_types=1);

namespace BashBox\Parser;

use BashBox\Ast\Arithmetic\ArithAssignmentNode;
use BashBox\Ast\Arithmetic\ArithBinaryNode;
use BashBox\Ast\Arithmetic\ArithExpr;
use BashBox\Ast\Arithmetic\ArithGroupNode;
use BashBox\Ast\Arithmetic\ArithNumberNode;
use BashBox\Ast\Arithmetic\ArithTernaryNode;
use BashBox\Ast\Arithmetic\ArithUnaryNode;
use BashBox\Ast\Arithmetic\ArithVariableNode;

final class ArithmeticParser
{
    private string $input;

    private int $pos = 0;

    private readonly int $len;

    public function __construct(string $input)
    {
        $this->input = trim($input);
        $this->len = strlen($this->input);
    }

    public function parse(): ArithExpr
    {
        if ($this->len === 0) {
            return new ArithNumberNode(0);
        }

        $expr = $this->parseComma();
        $this->skipWhitespace();

        return $expr;
    }

    private function parseComma(): ArithExpr
    {
        $left = $this->parseAssignment();

        while ($this->matchChar(',')) {
            $right = $this->parseAssignment();
            $left = new ArithBinaryNode(',', $left, $right);
        }

        return $left;
    }

    private function parseAssignment(): ArithExpr
    {
        $expr = $this->parseTernary();

        // Check for assignment operators
        if ($expr instanceof ArithVariableNode) {
            $this->skipWhitespace();
            $assignOps = ['<<=', '>>=', '**=', '+=', '-=', '*=', '/=', '%=', '&=', '|=', '^=', '='];

            foreach ($assignOps as $op) {
                if ($this->matchString($op)) {
                    $value = $this->parseAssignment();

                    return new ArithAssignmentNode($op, $expr->name, $value);
                }
            }
        }

        return $expr;
    }

    private function parseTernary(): ArithExpr
    {
        $condition = $this->parseOr();

        $this->skipWhitespace();

        if ($this->matchChar('?')) {
            $consequent = $this->parseAssignment();
            $this->skipWhitespace();
            $this->expectChar(':');
            $alternate = $this->parseAssignment();

            return new ArithTernaryNode($condition, $consequent, $alternate);
        }

        return $condition;
    }

    private function parseOr(): ArithExpr
    {
        $left = $this->parseAnd();

        while ($this->matchString('||')) {
            $right = $this->parseAnd();
            $left = new ArithBinaryNode('||', $left, $right);
        }

        return $left;
    }

    private function parseAnd(): ArithExpr
    {
        $left = $this->parseBitwiseOr();

        while ($this->matchString('&&')) {
            $right = $this->parseBitwiseOr();
            $left = new ArithBinaryNode('&&', $left, $right);
        }

        return $left;
    }

    private function parseBitwiseOr(): ArithExpr
    {
        $left = $this->parseBitwiseXor();

        while ($this->peekChar() === '|' && $this->peekCharAt(1) !== '|') {
            $this->pos++;
            $right = $this->parseBitwiseXor();
            $left = new ArithBinaryNode('|', $left, $right);
        }

        return $left;
    }

    private function parseBitwiseXor(): ArithExpr
    {
        $left = $this->parseBitwiseAnd();

        while ($this->matchChar('^')) {
            $right = $this->parseBitwiseAnd();
            $left = new ArithBinaryNode('^', $left, $right);
        }

        return $left;
    }

    private function parseBitwiseAnd(): ArithExpr
    {
        $left = $this->parseEquality();

        while ($this->peekChar() === '&' && $this->peekCharAt(1) !== '&') {
            $this->pos++;
            $right = $this->parseEquality();
            $left = new ArithBinaryNode('&', $left, $right);
        }

        return $left;
    }

    private function parseEquality(): ArithExpr
    {
        $left = $this->parseRelational();

        while (true) {
            $this->skipWhitespace();

            if ($this->matchString('==')) {
                $right = $this->parseRelational();
                $left = new ArithBinaryNode('==', $left, $right);
            } elseif ($this->matchString('!=')) {
                $right = $this->parseRelational();
                $left = new ArithBinaryNode('!=', $left, $right);
            } else {
                break;
            }
        }

        return $left;
    }

    private function parseRelational(): ArithExpr
    {
        $left = $this->parseShift();

        while (true) {
            $this->skipWhitespace();

            if ($this->matchString('<=')) {
                $right = $this->parseShift();
                $left = new ArithBinaryNode('<=', $left, $right);
            } elseif ($this->matchString('>=')) {
                $right = $this->parseShift();
                $left = new ArithBinaryNode('>=', $left, $right);
            } elseif ($this->peekChar() === '<' && $this->peekCharAt(1) !== '<') {
                $this->pos++;
                $right = $this->parseShift();
                $left = new ArithBinaryNode('<', $left, $right);
            } elseif ($this->peekChar() === '>' && $this->peekCharAt(1) !== '>') {
                $this->pos++;
                $right = $this->parseShift();
                $left = new ArithBinaryNode('>', $left, $right);
            } else {
                break;
            }
        }

        return $left;
    }

    private function parseShift(): ArithExpr
    {
        $left = $this->parseAdditive();

        while (true) {
            $this->skipWhitespace();

            if ($this->matchString('<<')) {
                $right = $this->parseAdditive();
                $left = new ArithBinaryNode('<<', $left, $right);
            } elseif ($this->matchString('>>')) {
                $right = $this->parseAdditive();
                $left = new ArithBinaryNode('>>', $left, $right);
            } else {
                break;
            }
        }

        return $left;
    }

    private function parseAdditive(): ArithExpr
    {
        $left = $this->parseMultiplicative();

        while (true) {
            $this->skipWhitespace();
            $ch = $this->peekChar();

            if ($ch === '+' && $this->peekCharAt(1) !== '+' && $this->peekCharAt(1) !== '=') {
                $this->pos++;
                $right = $this->parseMultiplicative();
                $left = new ArithBinaryNode('+', $left, $right);
            } elseif ($ch === '-' && $this->peekCharAt(1) !== '-' && $this->peekCharAt(1) !== '=') {
                $this->pos++;
                $right = $this->parseMultiplicative();
                $left = new ArithBinaryNode('-', $left, $right);
            } else {
                break;
            }
        }

        return $left;
    }

    private function parseMultiplicative(): ArithExpr
    {
        $left = $this->parseExponentiation();

        while (true) {
            $this->skipWhitespace();
            $ch = $this->peekChar();

            if ($ch === '*' && $this->peekCharAt(1) !== '*' && $this->peekCharAt(1) !== '=') {
                $this->pos++;
                $right = $this->parseExponentiation();
                $left = new ArithBinaryNode('*', $left, $right);
            } elseif ($ch === '/' && $this->peekCharAt(1) !== '=') {
                $this->pos++;
                $right = $this->parseExponentiation();
                $left = new ArithBinaryNode('/', $left, $right);
            } elseif ($ch === '%' && $this->peekCharAt(1) !== '=') {
                $this->pos++;
                $right = $this->parseExponentiation();
                $left = new ArithBinaryNode('%', $left, $right);
            } else {
                break;
            }
        }

        return $left;
    }

    private function parseExponentiation(): ArithExpr
    {
        $base = $this->parseUnary();

        $this->skipWhitespace();

        if ($this->matchString('**')) {
            $exp = $this->parseExponentiation(); // Right-associative

            return new ArithBinaryNode('**', $base, $exp);
        }

        return $base;
    }

    private function parseUnary(): ArithExpr
    {
        $this->skipWhitespace();
        $ch = $this->peekChar();

        // Prefix ++ and --
        if ($ch === '+' && $this->peekCharAt(1) === '+') {
            $this->pos += 2;
            $operand = $this->parseUnary();

            return new ArithUnaryNode('++', $operand, true);
        }

        if ($ch === '-' && $this->peekCharAt(1) === '-') {
            $this->pos += 2;
            $operand = $this->parseUnary();

            return new ArithUnaryNode('--', $operand, true);
        }

        // Unary +, -, !, ~
        if (in_array($ch, ['+', '-', '!', '~'], true)) {
            $this->pos++;
            $operand = $this->parseUnary();

            return new ArithUnaryNode($ch, $operand, true);
        }

        $expr = $this->parsePrimary();

        // Postfix ++ and --
        $this->skipWhitespace();

        if ($this->peekChar() === '+' && $this->peekCharAt(1) === '+') {
            $this->pos += 2;

            return new ArithUnaryNode('++', $expr, false);
        }

        if ($this->peekChar() === '-' && $this->peekCharAt(1) === '-') {
            $this->pos += 2;

            return new ArithUnaryNode('--', $expr, false);
        }

        return $expr;
    }

    private function parsePrimary(): ArithExpr
    {
        $this->skipWhitespace();

        if ($this->pos >= $this->len) {
            return new ArithNumberNode(0);
        }

        $ch = $this->input[$this->pos];

        // Grouping
        if ($ch === '(') {
            $this->pos++;
            $expr = $this->parseComma();
            $this->skipWhitespace();
            $this->expectChar(')');

            return new ArithGroupNode($expr);
        }

        // Number
        if (ctype_digit($ch)) {
            return $this->parseNumber();
        }

        // Variable with $ prefix
        if ($ch === '$') {
            $this->pos++;

            if ($this->pos < $this->len && $this->input[$this->pos] === '{') {
                // ${...}
                $this->pos++;
                $name = '';

                while ($this->pos < $this->len && $this->input[$this->pos] !== '}') {
                    $name .= $this->input[$this->pos];
                    $this->pos++;
                }

                if ($this->pos < $this->len) {
                    $this->pos++;
                }

                return new ArithVariableNode($name, true);
            }

            $name = $this->readIdentifier();

            return new ArithVariableNode($name, true);
        }

        // Variable name (identifier)
        if (ctype_alpha($ch) || $ch === '_') {
            $name = $this->readIdentifier();

            return new ArithVariableNode($name);
        }

        // Unknown - return 0
        $this->pos++;

        return new ArithNumberNode(0);
    }

    private function parseNumber(): ArithNumberNode
    {
        $start = $this->pos;

        // Handle hex, octal, binary
        if ($this->input[$this->pos] === '0' && $this->pos + 1 < $this->len) {
            $next = $this->input[$this->pos + 1];

            if ($next === 'x' || $next === 'X') {
                $this->pos += 2;

                while ($this->pos < $this->len && ctype_xdigit($this->input[$this->pos])) {
                    $this->pos++;
                }

                return new ArithNumberNode((int) substr($this->input, $start, $this->pos - $start));
            }
        }

        while ($this->pos < $this->len && ctype_digit($this->input[$this->pos])) {
            $this->pos++;
        }

        return new ArithNumberNode((int) substr($this->input, $start, $this->pos - $start));
    }

    private function readIdentifier(): string
    {
        $start = $this->pos;

        while ($this->pos < $this->len && (ctype_alnum($this->input[$this->pos]) || $this->input[$this->pos] === '_')) {
            $this->pos++;
        }

        return substr($this->input, $start, $this->pos - $start);
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < $this->len && (in_array($this->input[$this->pos], [' ', "\t", "\n"], true))) {
            $this->pos++;
        }
    }

    private function peekChar(): string
    {
        return $this->pos < $this->len ? $this->input[$this->pos] : '';
    }

    private function peekCharAt(int $offset): string
    {
        $idx = $this->pos + $offset;

        return $idx < $this->len ? $this->input[$idx] : '';
    }

    private function matchChar(string $ch): bool
    {
        $this->skipWhitespace();

        if ($this->pos < $this->len && $this->input[$this->pos] === $ch) {
            $this->pos++;

            return true;
        }

        return false;
    }

    private function matchString(string $str): bool
    {
        $this->skipWhitespace();
        $slen = strlen($str);

        if ($this->pos + $slen <= $this->len && substr($this->input, $this->pos, $slen) === $str) {
            // Make sure we're not matching a prefix of a longer operator
            if ($slen === 1 && $this->pos + 1 < $this->len) {
                $next = $this->input[$this->pos + 1];

                if ($str === '=' && $next === '=') {
                    return false;
                }
            }

            $this->pos += $slen;

            return true;
        }

        return false;
    }

    private function expectChar(string $ch): void
    {
        $this->skipWhitespace();

        if ($this->pos < $this->len && $this->input[$this->pos] === $ch) {
            $this->pos++;

            return;
        }

        // Silently skip on missing char (be lenient like bash)
    }
}
