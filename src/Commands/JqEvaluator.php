<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Limits;
use Generator;
use RuntimeException;
use stdClass;

final class JqEvaluator
{
    private int $steps = 0;

    public function __construct(private readonly Limits $limits) {}

    private function step(int $depth): void
    {
        if (++$this->steps > $this->limits->maxLoopIterations || $depth >= min(256, $this->limits->maxAstDepth)) {
            throw new ExecutionLimitException('jq: evaluation work/depth limit exceeded');
        }
    }

    /** @return Generator<int, mixed> */
    public function evaluate(JqFilter $jqFilter, mixed $input, int $depth = 0): Generator
    {
        $this->step($depth);
        $child = $jqFilter->children[0] ?? new JqFilter('empty');

        switch ($jqFilter->kind) {
            case 'identity':
                yield $input;

                break;

            case 'literal':
                yield $jqFilter->value;

                break;

            case 'empty':
                break;

            case ',':
                foreach ($jqFilter->children as $branch) {
                    yield from $this->evaluate($branch, $input, $depth + 1);
                }

                break;

            case '|':
                foreach ($this->evaluate($child, $input, $depth + 1) as $value) {
                    yield from $this->evaluate($jqFilter->children[1], $value, $depth + 1);
                }

                break;

            case '//':
                $found = false;

                foreach ($this->evaluate($child, $input, $depth + 1) as $value) {
                    if ($value !== null && $value !== false) {
                        $found = true;

                        yield $value;
                    }
                }

                if (! $found) {
                    yield from $this->evaluate($jqFilter->children[1], $input, $depth + 1);
                }

                break;

            case 'access':
                foreach ($this->evaluate($child, $input, $depth + 1) as $value) {
                    if ($value === null) {
                        yield null;
                    } elseif ($value instanceof stdClass && is_string($jqFilter->value)) {
                        yield $value->{$jqFilter->value} ?? null;
                    } elseif (is_array($value) && is_int($jqFilter->value)) {
                        $index = $jqFilter->value < 0 ? count($value) + $jqFilter->value : $jqFilter->value;

                        yield $value[$index] ?? null;
                    } else {
                        throw new RuntimeException('cannot index '.self::type($value).' with '.self::type($jqFilter->value));
                    }
                }

                break;

            case 'iterate':
                foreach ($this->evaluate($child, $input, $depth + 1) as $value) {
                    foreach ($this->items($value) as $item) {
                        $this->step($depth);

                        yield $item;
                    }
                }

                break;

            case 'array':
                yield $this->collect($this->evaluate($child, $input, $depth + 1));

                break;

            case 'map':
                $values = (function () use ($input, $child, $depth): Generator {
                    foreach ($this->items($input) as $value) {
                        yield from $this->evaluate($child, $value, $depth + 1);
                    }
                })();

                yield $this->collect($values);

                break;

            case 'object':
                yield from $this->object($jqFilter, $input, new stdClass, 0, $depth + 1);

                break;

            case 'select':
                foreach ($this->evaluate($child, $input, $depth + 1) as $value) {
                    if ($value !== false && $value !== null) {
                        yield $input;
                    }
                }

                break;

            case 'has':
                foreach ($this->evaluate($child, $input, $depth + 1) as $key) {
                    yield match (true) {
                        $input === null => false,
                        $input instanceof stdClass && is_string($key) => property_exists($input, $key),
                        is_array($input) && (is_int($key) || is_float($key)) => $key > -1 && $key < count($input) && array_key_exists((int) $key, $input),
                        default => throw new RuntimeException('has requires an object/string key or array/numeric index'),
                    };
                }

                break;

            case 'length':
                yield match (true) {
                    $input === null => 0,
                    is_string($input) => strlen($input) - (int) preg_match_all('/[\x80-\xbf]/', $input),
                    is_array($input) => count($input),
                    $input instanceof stdClass => count(get_object_vars($input)),
                    is_int($input), is_float($input) => abs($input),
                    default => throw new RuntimeException('length is not defined for '.self::type($input)),
                };

                break;

            case 'keys':
                if (is_array($input)) {
                    yield array_keys($input);
                } elseif ($input instanceof stdClass) {
                    $keys = array_map(strval(...), array_keys(get_object_vars($input)));
                    sort($keys, SORT_STRING);

                    yield $keys;
                } else {
                    throw new RuntimeException('keys requires an array or object');
                }

                break;

            case 'type':
                yield self::type($input);

                break;

            default:
                foreach ($this->evaluate($child, $input, $depth + 1) as $left) {
                    foreach ($this->evaluate($jqFilter->children[1], $input, $depth + 1) as $right) {
                        $comparison = $this->compare($left, $right, $depth + 1);

                        yield match ($jqFilter->kind) {
                            '==' => $comparison === 0,
                            '!=' => $comparison !== 0,
                            '<' => $comparison < 0,
                            '>' => $comparison > 0,
                            '<=' => $comparison <= 0,
                            '>=' => $comparison >= 0,
                            default => throw new RuntimeException('unsupported filter operator'),
                        };
                    }
                }
        }
    }

    /** @return array<array-key, mixed> */
    private function items(mixed $value): array
    {
        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }

        if (is_array($value)) {
            return $value;
        }

        throw new RuntimeException('cannot iterate over '.self::type($value));
    }

    /**
     * @param  Generator<int, mixed>  $values
     * @return list<mixed>
     */
    public function collect(Generator $values): array
    {
        $result = [];
        $size = 0;

        foreach ($values as $value) {
            if (count($result) >= $this->limits->maxArrayElements) {
                throw new ExecutionLimitException('jq: array fanout limit exceeded');
            }

            // Count expanded trees, not references: nested constructions can grow exponentially.
            $size += $this->weight($value);

            if ($size > $this->limits->maxOutputSize) {
                throw new ExecutionLimitException('jq: constructed value size limit exceeded');
            }

            $result[] = $value;
        }

        return $result;
    }

    /** @return Generator<int, stdClass> */
    private function object(JqFilter $jqFilter, mixed $input, stdClass $object, int $index, int $depth): Generator
    {
        $this->step($depth);

        if ($index === count($jqFilter->children)) {
            $this->weight($object);

            yield $object;

            return;
        }

        foreach ($this->evaluate($jqFilter->children[$index], $input, $depth + 1) as $value) {
            $next = clone $object;
            $next->{$jqFilter->keys[$index]} = $value;

            yield from $this->object($jqFilter, $input, $next, $index + 1, $depth + 1);
        }
    }

    /** Validate JSON trees and bound allocations and traversal before rendering or collecting them. */
    public function weight(mixed $value, int $depth = 0): int
    {
        $this->step($depth);

        if (is_array($value) || $value instanceof stdClass) {
            $size = 2;
            $count = 0;

            foreach ($this->items($value) as $key => $item) {
                if (++$count > $this->limits->maxArrayElements) {
                    throw new ExecutionLimitException('jq: array fanout limit exceeded');
                }

                $size += $this->weight($item, $depth + 1) + 1;

                if ($value instanceof stdClass) {
                    $size += $this->weight((string) $key, $depth + 1) + 1;
                }

                if ($size > $this->limits->maxOutputSize) {
                    throw new ExecutionLimitException('jq: value size limit exceeded');
                }
            }

            return $size;
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new RuntimeException('numbers outside the finite PHP numeric range are unsupported');
        }

        $size = 8;

        if (is_string($value)) {
            if (strlen($value) > $this->limits->maxStringLength) {
                throw new ExecutionLimitException('jq: string size limit exceeded');
            }

            $size = strlen($value) + 2 + (int) preg_match_all('/["\\\\\x08\x09\x0a\x0c\x0d]/', $value)
                + 5 * (int) preg_match_all('/[\x00-\x07\x0b\x0e-\x1f]/', $value);
        }

        if ($size > $this->limits->maxOutputSize) {
            throw new ExecutionLimitException('jq: value size limit exceeded');
        }

        return $size;
    }

    public static function type(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => 'array',
            default => 'object',
        };
    }

    private function compare(mixed $left, mixed $right, int $depth): int
    {
        $this->step($depth);
        $rank = static fn (mixed $value): int => match (true) {
            $value === null => 0,
            $value === false => 1,
            $value === true => 2,
            is_int($value), is_float($value) => 3,
            is_string($value) => 4,
            is_array($value) => 5,
            default => 6,
        };

        if ($rank($left) !== $rank($right)) {
            return $rank($left) <=> $rank($right);
        }

        if ($left instanceof stdClass && $right instanceof stdClass) {
            $a = get_object_vars($left);
            $b = get_object_vars($right);
            ksort($a, SORT_STRING);
            ksort($b, SORT_STRING);
            $keys = $this->compare(array_map(strval(...), array_keys($a)), array_map(strval(...), array_keys($b)), $depth + 1);

            return $keys !== 0 ? $keys : $this->compare(array_values($a), array_values($b), $depth + 1);
        }

        if (is_array($left) && is_array($right)) {
            foreach ($left as $index => $value) {
                if (! array_key_exists($index, $right)) {
                    return 1;
                }

                $comparison = $this->compare($value, $right[$index], $depth + 1);

                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return count($left) <=> count($right);
        }

        if (is_string($left) && is_string($right)) {
            return strcmp($left, $right);
        }

        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return $left <=> $right;
        }

        return 0;
    }
}
