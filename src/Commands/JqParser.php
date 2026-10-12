<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Limits;
use RuntimeException;

final class JqParser
{
    /** @var list<string> */
    private array $tokens = [];

    private int $position = 0;

    /** @param array<string, mixed> $variables */
    public function __construct(string $filter, private readonly Limits $limits, private readonly array $variables = [])
    {
        if (strlen($filter) > $limits->maxInputSize) {
            throw new ExecutionLimitException('jq: filter size limit exceeded');
        }

        $offset = 0;

        while ($offset < strlen($filter)) {
            if (preg_match('/\G\s+/A', $filter, $match, 0, $offset) === 1) {
                $offset += strlen($match[0]);

                continue;
            }

            if (preg_match('/\G(?:"(?:[^"\\\\\x00-\x1f]|\\\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|\$[A-Za-z_][A-Za-z_0-9]*|\.[A-Za-z_][A-Za-z_0-9]*|[A-Za-z_][A-Za-z_0-9]*|\/\/|==|!=|<=|>=|[.\[\]{}():,|<>])/A', $filter, $match, 0, $offset) !== 1) {
                throw new RuntimeException('unsupported filter syntax at byte '.$offset);
            }

            if (count($this->tokens) >= $limits->maxTokens) {
                throw new ExecutionLimitException('jq: token limit exceeded');
            }

            $this->tokens[] = $match[0];
            $offset += strlen($match[0]);
        }
    }

    public function parse(): JqFilter
    {
        $jqFilter = $this->expression();

        if ($this->peek() !== '') {
            throw new RuntimeException('unsupported filter token: '.$this->peek());
        }

        return $jqFilter;
    }

    private function peek(): string
    {
        return $this->tokens[$this->position] ?? '';
    }

    /** @phpstan-impure */
    private function take(): string
    {
        $token = $this->peek();
        $this->position++;

        return $token;
    }

    private function expect(string $token): void
    {
        if ($this->take() !== $token) {
            throw new RuntimeException('expected '.$token.' in filter');
        }
    }

    private function expression(int $minimum = 0, int $depth = 0, bool $comma = true): JqFilter
    {
        if ($depth >= min(256, $this->limits->maxAstDepth)) {
            throw new ExecutionLimitException('jq: filter depth limit exceeded');
        }

        $token = $this->take();
        $node = match (true) {
            $token === '.' => new JqFilter('identity'),
            $token === '(' => $this->group(')', $depth),
            $token === '[' => $this->peek() === ']' ? $this->emptyArray() : new JqFilter('array', children: [$this->group(']', $depth)]),
            $token === '{' => $this->object($depth),
            str_starts_with($token, '$') => $this->variable(substr($token, 1)),
            str_starts_with($token, '.') => new JqFilter('access', substr($token, 1), [new JqFilter('identity')]),
            in_array($token, ['map', 'select', 'has'], true) => $this->call($token, $depth),
            in_array($token, ['length', 'keys', 'type', 'empty'], true) => new JqFilter($token),
            in_array($token, ['true', 'false', 'null'], true), str_starts_with($token, '"'), preg_match('/^-?\d/', $token) === 1 => $this->literal($token),
            default => throw new RuntimeException('unsupported filter: '.($token === '' ? '(empty)' : $token)),
        };

        if ($token === '.' && str_starts_with($this->peek(), '"')) {
            $node = new JqFilter('access', $this->key($this->take()), [$node]);
        }

        while (true) {
            if (str_starts_with($this->peek(), '.')) {
                $token = $this->take();
                $key = $token === '.' ? $this->take() : substr($token, 1);

                if ($token === '.' && ! str_starts_with($key, '"')) {
                    throw new RuntimeException('expected field name adjacent to dot or quoted key');
                }

                $node = new JqFilter('access', $this->key($key), [$node]);

                continue;
            }

            if ($this->peek() === '[') {
                $this->take();

                if ($this->peek() === ']') {
                    $node = new JqFilter('iterate', children: [$node]);
                } else {
                    $index = $this->take();

                    if (preg_match('/^-?\d+$/', $index) === 1) {
                        $number = json_decode($index, false, 512, JSON_THROW_ON_ERROR);

                        if (! is_int($number)) {
                            throw new RuntimeException('indexes outside the PHP integer range are unsupported');
                        }

                        $node = new JqFilter('access', $number, [$node]);
                    } elseif (str_starts_with($index, '"')) {
                        $node = new JqFilter('access', $this->key($index), [$node]);
                    } else {
                        throw new RuntimeException('only literal string keys and integer indexes are supported');
                    }
                }

                $this->expect(']');

                continue;
            }

            $operator = $this->peek();

            if ($operator === ',' && ! $comma) {
                break;
            }

            $precedence = match ($operator) {
                '|' => 1,
                ',' => 2,
                '//' => 3,
                '==', '!=', '<', '>', '<=', '>=' => 4,
                default => -1,
            };

            if ($precedence < $minimum) {
                break;
            }

            $this->take();
            $node = new JqFilter($operator, children: [$node, $this->expression($precedence + 1, $depth + 1, $comma)]);
        }

        return $node;
    }

    private function variable(string $name): JqFilter
    {
        if (! array_key_exists($name, $this->variables)) {
            throw new RuntimeException('undefined variable');
        }

        return new JqFilter('literal', $this->variables[$name]);
    }

    private function literal(string $token): JqFilter
    {
        $value = json_decode($token, false, 512, JSON_THROW_ON_ERROR);

        if (is_float($value) && ! is_finite($value)) {
            throw new RuntimeException('numbers outside the finite PHP numeric range are unsupported');
        }

        return new JqFilter('literal', $value);
    }

    private function key(string $token): string
    {
        if (str_starts_with($token, '"')) {
            $key = json_decode($token, false, 512, JSON_THROW_ON_ERROR);

            if (is_string($key)) {
                if (str_starts_with($key, "\0")) {
                    throw new RuntimeException('object keys starting with NUL are unsupported by PHP objects');
                }

                return $key;
            }
        } elseif (preg_match('/^[A-Za-z_][A-Za-z_0-9]*$/', $token) === 1) {
            return $token;
        }

        throw new RuntimeException('expected field name');
    }

    private function group(string $end, int $depth): JqFilter
    {
        $jqFilter = $this->expression(0, $depth + 1);
        $this->expect($end);

        return $jqFilter;
    }

    private function emptyArray(): JqFilter
    {
        $this->expect(']');

        return new JqFilter('array');
    }

    private function call(string $name, int $depth): JqFilter
    {
        $this->expect('(');

        return new JqFilter($name, children: [$this->group(')', $depth)]);
    }

    private function object(int $depth): JqFilter
    {
        $keys = [];
        $children = [];

        if ($this->peek() !== '}') {
            do {
                if (count($keys) >= $this->limits->maxArrayElements) {
                    throw new ExecutionLimitException('jq: object fanout limit exceeded');
                }

                $key = $this->key($this->take());
                $keys[] = $key;

                if ($this->peek() === ':') {
                    $this->take();
                    $children[] = $this->expression(0, $depth + 1, false);
                } else {
                    $children[] = new JqFilter('access', $key, [new JqFilter('identity')]);
                }

                if ($this->peek() !== ',') {
                    break;
                }

                $this->take();
            } while (true);
        }

        $this->expect('}');

        return new JqFilter('object', children: $children, keys: $keys);
    }
}
