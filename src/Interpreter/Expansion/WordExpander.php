<?php

declare(strict_types=1);

namespace BashBox\Interpreter\Expansion;

use BashBox\Ast\ParameterOps\AssignDefaultOp;
use BashBox\Ast\ParameterOps\CaseModificationOp;
use BashBox\Ast\ParameterOps\DefaultValueOp;
use BashBox\Ast\ParameterOps\ErrorIfUnsetOp;
use BashBox\Ast\ParameterOps\LengthOp;
use BashBox\Ast\ParameterOps\PatternRemovalOp;
use BashBox\Ast\ParameterOps\PatternReplacementOp;
use BashBox\Ast\ParameterOps\SubstringOp;
use BashBox\Ast\ParameterOps\TransformOp;
use BashBox\Ast\ParameterOps\UseAlternativeOp;
use BashBox\Ast\Parts\ArithmeticExpansionPart;
use BashBox\Ast\Parts\BraceExpansionPart;
use BashBox\Ast\Parts\CommandSubstitutionPart;
use BashBox\Ast\Parts\DoubleQuotedPart;
use BashBox\Ast\Parts\EscapedPart;
use BashBox\Ast\Parts\GlobPart;
use BashBox\Ast\Parts\LiteralPart;
use BashBox\Ast\Parts\ParameterExpansionPart;
use BashBox\Ast\Parts\SingleQuotedPart;
use BashBox\Ast\Parts\TildeExpansionPart;
use BashBox\Ast\WordNode;
use BashBox\Ast\WordPart;
use BashBox\Interpreter\Interpreter;
use BashBox\Interpreter\InterpreterState;

final readonly class WordExpander
{
    public function __construct(
        private InterpreterState $state,
        private Interpreter $interpreter,
    ) {}

    public function expand(WordNode $word): string
    {
        $result = '';

        foreach ($word->parts as $part) {
            $result .= $this->expandPart($part);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function expandToList(WordNode $word): array
    {
        $expanded = $this->expand($word);

        // Word split by IFS
        $ifs = $this->state->getVar('IFS') ?? " \t\n";

        return $this->splitByIFS($expanded, $ifs);
    }

    private function expandPart(WordPart $part): string
    {
        if ($part instanceof LiteralPart) {
            return $this->expandLiteralValue($part->value);
        }

        if ($part instanceof SingleQuotedPart) {
            return $part->value;
        }

        if ($part instanceof EscapedPart) {
            return $part->value;
        }

        if ($part instanceof DoubleQuotedPart) {
            $result = '';
            foreach ($part->parts as $inner) {
                $result .= $this->expandPart($inner);
            }

            return $result;
        }

        if ($part instanceof ParameterExpansionPart) {
            return $this->expandParameter($part);
        }

        if ($part instanceof CommandSubstitutionPart) {
            $result = $this->interpreter->executeScript($part->body);

            return rtrim($result->stdout, "\n");
        }

        if ($part instanceof ArithmeticExpansionPart) {
            $value = $this->interpreter->evaluateArithmeticExpression($part->expression);

            return (string) $value;
        }

        if ($part instanceof TildeExpansionPart) {
            if ($part->user === null) {
                return $this->state->getVar('HOME') ?? '/home/user';
            }

            return '/home/'.$part->user;
        }

        if ($part instanceof GlobPart) {
            return $part->pattern;
        }

        if ($part instanceof BraceExpansionPart) {
            return $this->expandBrace($part);
        }

        return '';
    }

    private function expandLiteralValue(string $value): string
    {
        // Process inline variable expansions within literal text
        // This handles $VAR, ${VAR}, ${VAR:-default}, $((expr)), $(cmd) in raw token text
        $result = '';
        $len = strlen($value);
        $i = 0;

        // Tilde expansion at start of word
        if ($len > 0 && $value[0] === '~') {
            $slashPos = strpos($value, '/', 1);
            if ($len === 1 || $slashPos === 1) {
                // ~ or ~/...
                $home = $this->state->getVar('HOME') ?? '/home/user';
                $result .= $home;
                $i = 1;
            } elseif ($slashPos !== false) {
                // ~user/...
                $user = substr($value, 1, $slashPos - 1);
                $result .= '/home/'.$user;
                $i = (int) $slashPos;
            } elseif (ctype_alnum(substr($value, 1)) || $value === '~') {
                // ~user (no slash)
                $user = substr($value, 1);
                if ($user === '') {
                    $result .= $this->state->getVar('HOME') ?? '/home/user';
                } else {
                    $result .= '/home/'.$user;
                }

                $i = $len;
            }
        }

        while ($i < $len) {
            if ($value[$i] === '$' && $i + 1 < $len) {
                $expanded = $this->expandDollarInLiteral($value, $i);
                $result .= $expanded['value'];
                $i = $expanded['pos'];

                continue;
            }

            if ($value[$i] === '\\' && $i + 1 < $len) {
                $next = $value[$i + 1];
                // In unquoted context, backslash-newline is line continuation (already handled by lexer)
                // Other backslash escapes pass through
                $result .= $next;
                $i += 2;

                continue;
            }

            if ($value[$i] === "'" && $i < $len) {
                // Single-quoted section in literal
                $i++;
                while ($i < $len && $value[$i] !== "'") {
                    $result .= $value[$i];
                    $i++;
                }

                if ($i < $len) {
                    $i++; // Skip closing quote
                }

                continue;
            }

            if ($value[$i] === '"') {
                // Double-quoted section in literal
                $i++;
                while ($i < $len && $value[$i] !== '"') {
                    if ($value[$i] === '$' && $i + 1 < $len) {
                        $expanded = $this->expandDollarInLiteral($value, $i);
                        $result .= $expanded['value'];
                        $i = $expanded['pos'];

                        continue;
                    }

                    if ($value[$i] === '\\' && $i + 1 < $len) {
                        $next = $value[$i + 1];
                        if (in_array($next, ['"', '$', '\\', '`'], true)) {
                            $result .= $next;
                            $i += 2;

                            continue;
                        }
                    }

                    $result .= $value[$i];
                    $i++;
                }

                if ($i < $len) {
                    $i++; // Skip closing quote
                }

                continue;
            }

            if ($value[$i] === '`') {
                // Backtick command substitution
                $i++;
                $cmd = '';
                while ($i < $len && $value[$i] !== '`') {
                    if ($value[$i] === '\\' && $i + 1 < $len) {
                        $cmd .= $value[$i + 1];
                        $i += 2;

                        continue;
                    }

                    $cmd .= $value[$i];
                    $i++;
                }

                if ($i < $len) {
                    $i++; // Skip closing backtick
                }

                $cmdResult = $this->interpreter->execSubcommand($cmd);
                $result .= rtrim($cmdResult->stdout, "\n");

                continue;
            }

            $result .= $value[$i];
            $i++;
        }

        return $result;
    }

    /**
     * @return array{value: string, pos: int}
     */
    private function expandDollarInLiteral(string $value, int $i): array
    {
        $len = strlen($value);
        $i++; // Skip $

        if ($i >= $len) {
            return ['value' => '$', 'pos' => $i];
        }

        $ch = $value[$i];

        // $(( — arithmetic expansion
        if ($ch === '(' && $i + 1 < $len && $value[$i + 1] === '(') {
            $i += 2;
            $expr = '';

            while ($i < $len) {
                if ($value[$i] === ')' && $i + 1 < $len && $value[$i + 1] === ')') {
                    $i += 2;

                    break;
                }

                $expr .= $value[$i];
                $i++;
            }

            $result = $this->interpreter->evaluateArithmeticString($expr);

            return ['value' => (string) $result, 'pos' => $i];
        }

        // $( — command substitution
        if ($ch === '(') {
            $i++;
            $depth = 1;
            $cmd = '';

            while ($i < $len) {
                if ($value[$i] === '(') {
                    $depth++;
                } elseif ($value[$i] === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $i++;

                        break;
                    }
                }

                $cmd .= $value[$i];
                $i++;
            }

            $cmdResult = $this->interpreter->execSubcommand($cmd);

            return ['value' => rtrim($cmdResult->stdout, "\n"), 'pos' => $i];
        }

        // ${ — parameter expansion
        if ($ch === '{') {
            $i++;
            $content = '';
            $depth = 1;

            while ($i < $len) {
                if ($value[$i] === '{') {
                    $depth++;
                } elseif ($value[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $i++;

                        break;
                    }
                }

                $content .= $value[$i];
                $i++;
            }

            $expanded = $this->expandParameterString($content);

            return ['value' => $expanded, 'pos' => $i];
        }

        // $var
        if (ctype_alpha($ch) || $ch === '_') {
            $varName = '';
            while ($i < $len && (ctype_alnum($value[$i]) || $value[$i] === '_')) {
                $varName .= $value[$i];
                $i++;
            }

            // Check for array subscript $var[...]
            if ($i < $len && $value[$i] === '[') {
                $i++;
                $subscript = '';
                $bracketDepth = 1;
                while ($i < $len) {
                    if ($value[$i] === '[') {
                        $bracketDepth++;
                    } elseif ($value[$i] === ']') {
                        $bracketDepth--;
                        if ($bracketDepth === 0) {
                            $i++;

                            break;
                        }
                    }

                    $subscript .= $value[$i];
                    $i++;
                }

                if ($subscript === '@' || $subscript === '*') {
                    $arr = $this->state->arrays[$varName] ?? [];

                    return ['value' => implode(' ', $arr), 'pos' => $i];
                }

                $arr = $this->state->arrays[$varName] ?? [];
                $idx = is_numeric($subscript) ? (int) $subscript : $subscript;

                return ['value' => (string) ($arr[$idx] ?? ''), 'pos' => $i];
            }

            $val = $this->state->getVar($varName) ?? $this->state->getSpecialVar($varName) ?? '';

            return ['value' => $val, 'pos' => $i];
        }

        // Special variables: $?, $$, $#, $!, $*, $@, $-, $0-$9
        if (in_array($ch, ['?', '!', '$', '#', '*', '@', '-', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], true)) {
            $i++;

            if (ctype_digit($ch)) {
                $idx = (int) $ch;
                if ($idx === 0) {
                    return ['value' => 'bashbox', 'pos' => $i];
                }

                $val = $this->state->positionalParams[$idx - 1] ?? '';

                return ['value' => $val, 'pos' => $i];
            }

            $val = $this->state->getSpecialVar($ch) ?? '';

            return ['value' => $val, 'pos' => $i];
        }

        return ['value' => '$', 'pos' => $i];
    }

    private function expandParameterString(string $content): string
    {
        // Handle # prefix (length)
        if (str_starts_with($content, '#')) {
            $varName = substr($content, 1);
            $val = $this->state->getVar($varName) ?? $this->state->getSpecialVar($varName) ?? '';

            if ($varName === '@' || $varName === '*') {
                return (string) count($this->state->positionalParams);
            }

            // Array length
            if (str_contains($varName, '[')) {
                $arrayName = substr($varName, 0, (int) strpos($varName, '['));
                $subscript = substr($varName, (int) strpos($varName, '[') + 1, -1);

                if ($subscript === '@' || $subscript === '*') {
                    return (string) count($this->state->arrays[$arrayName] ?? []);
                }

                $arr = $this->state->arrays[$arrayName] ?? [];
                $idx = is_numeric($subscript) ? (int) $subscript : $subscript;

                return (string) strlen((string) ($arr[$idx] ?? ''));
            }

            return (string) strlen($val);
        }

        // Handle ! prefix (indirection)
        if (str_starts_with($content, '!')) {
            $varName = substr($content, 1);
            $intermediate = $this->state->getVar($varName) ?? '';

            return $this->state->getVar($intermediate) ?? '';
        }

        // Find operator position
        foreach ([':-', ':=', ':?', ':+', '-', '=', '?', '+'] as $op) {
            $opPos = strpos($content, $op);
            if ($opPos !== false && $opPos > 0) {
                $varName = substr($content, 0, $opPos);
                $word = substr($content, $opPos + strlen($op));
                $checkEmpty = str_starts_with($op, ':');
                $baseOp = ltrim($op, ':');
                $val = $this->state->getVar($varName) ?? $this->state->getSpecialVar($varName);

                $isUnset = $val === null;
                $isEmpty = $val === '' || $val === null;
                $shouldApply = $checkEmpty ? $isEmpty : $isUnset;

                return match ($baseOp) {
                    '-' => $shouldApply ? $this->expandLiteralValue($word) : ($val ?? ''),
                    '=' => $shouldApply ? (function () use ($varName, $word): string {
                        $expanded = $this->expandLiteralValue($word);
                        $this->state->setVar($varName, $expanded);

                        return $expanded;
                    })() : ($val ?? ''),
                    '?' => $shouldApply ? throw new \BashBox\Exceptions\BashException(
                        sprintf('bash: %s: ', $varName).($word !== '' ? $this->expandLiteralValue($word) : 'parameter null or not set'),
                    ) : ($val ?? ''),
                    default => $shouldApply ? '' : $this->expandLiteralValue($word),
                };
            }
        }

        // Pattern operations
        foreach (['%%', '##', '%', '#'] as $op) {
            $opPos = strpos($content, $op);
            if ($opPos !== false && $opPos > 0) {
                $varName = substr($content, 0, $opPos);
                $pattern = substr($content, $opPos + strlen($op));
                $val = $this->state->getVar($varName) ?? '';

                $greedy = strlen($op) === 2;
                $side = ($op[0] === '#') ? 'prefix' : 'suffix';

                return $this->applyPatternRemoval($val, $pattern, $side, $greedy);
            }
        }

        // Substring: ${var:offset} or ${var:offset:length}
        $colonPos = strpos($content, ':');
        if ($colonPos !== false && $colonPos > 0) {
            $varName = substr($content, 0, $colonPos);
            $rest = substr($content, $colonPos + 1);

            // Check it's not an operator like :- :+ := :?
            if ($rest !== '' && ! in_array($rest[0], ['-', '+', '=', '?'], true)) {
                $val = $this->state->getVar($varName) ?? '';
                $parts = explode(':', $rest, 2);
                $offset = (int) $parts[0];
                $length = isset($parts[1]) ? (int) $parts[1] : null;

                if ($offset < 0) {
                    $offset = max(0, strlen($val) + $offset);
                }

                if ($length !== null) {
                    if ($length < 0) {
                        $endPos = strlen($val) + $length;

                        return $endPos > $offset ? substr($val, $offset, $endPos - $offset) : '';
                    }

                    return substr($val, $offset, $length);
                }

                return substr($val, $offset);
            }
        }

        // Pattern replacement: ${var/pattern/replacement} or ${var//pattern/replacement}
        $slashPos = strpos($content, '/');
        if ($slashPos !== false && $slashPos > 0) {
            $varName = substr($content, 0, $slashPos);
            $rest = substr($content, $slashPos + 1);
            $all = false;

            if (str_starts_with($rest, '/')) {
                $all = true;
                $rest = substr($rest, 1);
            }

            $parts = explode('/', $rest, 2);
            $pattern = $parts[0];
            $replacement = $parts[1] ?? '';
            $val = $this->state->getVar($varName) ?? '';

            return $this->applyPatternReplacement($val, $pattern, $replacement, $all);
        }

        // Case modification: ${var^}, ${var^^}, ${var,}, ${var,,}
        $len = strlen($content);
        if ($len >= 2) {
            $lastTwo = substr($content, -2);
            $lastOne = $content[$len - 1];

            if ($lastTwo === '^^') {
                $varName = substr($content, 0, -2);
                $val = $this->state->getVar($varName) ?? '';

                return mb_strtoupper($val);
            }

            if ($lastTwo === ',,') {
                $varName = substr($content, 0, -2);
                $val = $this->state->getVar($varName) ?? '';

                return mb_strtolower($val);
            }

            if ($lastOne === '^') {
                $varName = substr($content, 0, -1);
                $val = $this->state->getVar($varName) ?? '';

                return $val !== '' ? mb_strtoupper(mb_substr($val, 0, 1)).mb_substr($val, 1) : '';
            }

            if ($lastOne === ',') {
                $varName = substr($content, 0, -1);
                $val = $this->state->getVar($varName) ?? '';

                return $val !== '' ? mb_strtolower(mb_substr($val, 0, 1)).mb_substr($val, 1) : '';
            }
        }

        // Handle array subscripts
        if (str_contains($content, '[')) {
            $bracketPos = strpos($content, '[');
            $arrayName = substr($content, 0, $bracketPos);
            $subscript = substr($content, $bracketPos + 1, -1);

            if ($subscript === '@' || $subscript === '*') {
                $arr = $this->state->arrays[$arrayName] ?? [];

                return implode(' ', $arr);
            }

            $arr = $this->state->arrays[$arrayName] ?? [];
            $idx = is_numeric($subscript) ? (int) $subscript : $subscript;

            return (string) ($arr[$idx] ?? '');
        }

        // Simple variable
        return $this->state->getVar($content) ?? $this->state->getSpecialVar($content) ?? '';
    }

    private function expandParameter(ParameterExpansionPart $part): string
    {
        $val = $this->state->getVar($part->parameter)
            ?? $this->state->getSpecialVar($part->parameter)
            ?? '';

        if (! $part->operation instanceof \BashBox\Ast\ParameterOps\ParameterOperation) {
            return $val;
        }

        $op = $part->operation;

        if ($op instanceof LengthOp) {
            return (string) strlen($val);
        }

        if ($op instanceof DefaultValueOp) {
            $shouldApply = $op->checkEmpty && $val === '';

            return $shouldApply ? $this->expand($op->word) : $val;
        }

        if ($op instanceof AssignDefaultOp) {
            $shouldApply = $op->checkEmpty && $val === '';
            if ($shouldApply) {
                $expanded = $this->expand($op->word);
                $this->state->setVar($part->parameter, $expanded);

                return $expanded;
            }

            return $val;
        }

        if ($op instanceof UseAlternativeOp) {
            $shouldApply = $op->checkEmpty && $val === '';

            return $shouldApply ? '' : $this->expand($op->word);
        }

        if ($op instanceof ErrorIfUnsetOp) {
            $shouldApply = $op->checkEmpty && $val === '';
            if ($shouldApply) {
                $msg = $op->word instanceof \BashBox\Ast\WordNode ? $this->expand($op->word) : 'parameter null or not set';
                throw new \BashBox\Exceptions\BashException(sprintf('bash: %s: %s', $part->parameter, $msg));
            }

            return $val;
        }

        if ($op instanceof SubstringOp) {
            $offset = $this->interpreter->evaluateArithmeticExpression($op->offset);
            $length = $op->length instanceof \BashBox\Ast\ArithmeticExpressionNode ? $this->interpreter->evaluateArithmeticExpression($op->length) : null;

            if ($offset < 0) {
                $offset = max(0, strlen($val) + $offset);
            }

            return $length !== null ? substr($val, $offset, $length) : substr($val, $offset);
        }

        if ($op instanceof PatternRemovalOp) {
            $pattern = $this->expand($op->pattern);

            return $this->applyPatternRemoval($val, $pattern, $op->side, $op->greedy);
        }

        if ($op instanceof PatternReplacementOp) {
            $pattern = $this->expand($op->pattern);
            $replacement = $op->replacement instanceof \BashBox\Ast\WordNode ? $this->expand($op->replacement) : '';

            return $this->applyPatternReplacement($val, $pattern, $replacement, $op->all);
        }

        if ($op instanceof CaseModificationOp) {
            if ($op->direction === 'upper') {
                return $op->all ? mb_strtoupper($val) : ($val !== '' ? mb_strtoupper(mb_substr($val, 0, 1)).mb_substr($val, 1) : '');
            }

            return $op->all ? mb_strtolower($val) : ($val !== '' ? mb_strtolower(mb_substr($val, 0, 1)).mb_substr($val, 1) : '');
        }

        if ($op instanceof TransformOp) {
            return match ($op->operator) {
                'Q' => "'".str_replace("'", "'\\''", $val)."'",
                'U' => mb_strtoupper($val),
                'L' => mb_strtolower($val),
                'u' => $val !== '' ? mb_strtoupper(mb_substr($val, 0, 1)).mb_substr($val, 1) : '',
                default => $val,
            };
        }

        return $val;
    }

    private function expandBrace(BraceExpansionPart $part): string
    {
        $results = [];

        foreach ($part->items as $item) {
            if ($item['type'] === 'Word' && isset($item['word'])) {
                $results[] = $this->expand($item['word']);
            } elseif ($item['type'] === 'Range') {
                $start = $item['start'] ?? 0;
                $end = $item['end'] ?? 0;
                $step = $item['step'] ?? 1;

                if (is_int($start) && is_int($end)) {
                    $step = max(1, abs($step));
                    if ($start <= $end) {
                        for ($j = $start; $j <= $end; $j += $step) {
                            $results[] = (string) $j;
                        }
                    } else {
                        for ($j = $start; $j >= $end; $j -= $step) {
                            $results[] = (string) $j;
                        }
                    }
                }
            }
        }

        return implode(' ', $results);
    }

    private function applyPatternRemoval(string $val, string $pattern, string $side, bool $greedy): string
    {
        if ($side === 'prefix') {
            if ($greedy) {
                $regex = $this->patternToRegex($pattern);
                $result = preg_replace('/^'.$regex.'/', '', $val);
            } else {
                // Shortest prefix: use non-greedy quantifiers
                $regex = $this->patternToRegex($pattern, lazy: true);
                $result = preg_replace('/^'.$regex.'/', '', $val, 1);
            }
        } elseif ($greedy) {
            $regex = $this->patternToRegex($pattern);
            $result = preg_replace('/^(.*?)'.$this->patternToRegex($pattern).'$/', '$1', $val);
        } else {
            // Shortest suffix: find the longest prefix before the match
            $regex = $this->patternToRegex($pattern, lazy: true);
            $result = preg_replace('/^(.*)'.$regex.'$/', '$1', $val);
        }

        return $result ?? $val;
    }

    private function applyPatternReplacement(string $val, string $pattern, string $replacement, bool $all): string
    {
        $regex = $this->patternToRegex($pattern);

        if ($all) {
            return (string) preg_replace('/'.$regex.'/', $replacement, $val);
        }

        return (string) preg_replace('/'.$regex.'/', $replacement, $val, 1);
    }

    private function patternToRegex(string $pattern, bool $lazy = false): string
    {
        $result = '';
        $len = strlen($pattern);

        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];
            $result .= match ($ch) {
                '*' => $lazy ? '.*?' : '.*',
                '?' => '.',
                '\\' => $i + 1 < $len ? preg_quote($pattern[++$i], '/') : '\\\\',
                default => preg_quote($ch, '/'),
            };
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function splitByIFS(string $str, string $ifs): array
    {
        if ($str === '') {
            return [''];
        }

        if ($ifs === '') {
            return [$str];
        }

        $parts = [];
        $current = '';
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            if (str_contains($ifs, $str[$i])) {
                $parts[] = $current;
                $current = '';
                // Skip consecutive IFS whitespace
                while ($i + 1 < $len && str_contains($ifs, $str[$i + 1]) && ctype_space($str[$i + 1])) {
                    $i++;
                }
            } else {
                $current .= $str[$i];
            }
        }

        $parts[] = $current;

        return array_values(array_filter($parts, fn (string $p): bool => $p !== ''));
    }
}
