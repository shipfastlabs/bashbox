<?php

declare(strict_types=1);

namespace BashBox\Interpreter;

use BashBox\Ast\AssignmentNode;
use BashBox\Ast\Parts\LiteralPart;
use BashBox\Ast\WordNode;
use BashBox\Exceptions\AssignmentException;
use BashBox\Interpreter\Expansion\WordExpander;

/**
 * Expands `name=value`, `name[sub]=value` and `name=(...)` and stores them.
 *
 * @phpstan-type Assignment array{type: 'scalar'|'array'|'element', name: string, value?: string, append: bool, elements?: list<array{int|string|null, string}>, subscript?: int|string}
 */
final readonly class Assignments
{
    public function __construct(
        private InterpreterState $interpreterState,
        private WordExpander $wordExpander,
        private ArithmeticEvaluator $arithmeticEvaluator,
    ) {}

    /** @return Assignment */
    public function resolve(AssignmentNode $assignmentNode): array
    {
        if ($assignmentNode->array !== null) {
            $elements = [];

            foreach ($assignmentNode->array as $element) {
                // `[key]=value` is read from the raw text, before quote removal, so a quoted value stays one word
                if (preg_match('/^\[([^\]]+)\]=(.*)$/s', WordExpander::literalText($element), $m) === 1) {
                    $key = $this->wordExpander->expand(new WordNode([new LiteralPart($m[1])]));
                    $elements[] = [$this->normalizeKey($key), $this->wordExpander->expand(new WordNode([new LiteralPart($m[2])]))];

                    continue;
                }

                foreach ($this->wordExpander->expandToList($element) as $value) {
                    $elements[] = [null, $value];
                }
            }

            return [
                'type' => 'array',
                'name' => $assignmentNode->name,
                'append' => $assignmentNode->append,
                'elements' => $elements,
            ];
        }

        if (preg_match('/^([a-zA-Z_]\w*)\[(.+)\]$/', $assignmentNode->name, $matches) === 1) {
            return [
                'type' => 'element',
                'name' => $matches[1],
                'subscript' => $this->expandSubscript($matches[2]),
                'append' => $assignmentNode->append,
                'value' => $assignmentNode->value instanceof WordNode ? $this->wordExpander->expand($assignmentNode->value) : '',
            ];
        }

        return [
            'type' => 'scalar',
            'name' => $assignmentNode->name,
            'append' => $assignmentNode->append,
            'value' => $assignmentNode->value instanceof WordNode ? $this->wordExpander->expand($assignmentNode->value) : '',
        ];
    }

    /** @param Assignment $assignment */
    public function apply(array $assignment): void
    {
        $state = $this->interpreterState;
        $name = $assignment['name'];

        if ($assignment['type'] === 'element') {
            $state->setElement($name, $this->arrayIndex($name, $assignment['subscript'] ?? 0), $assignment['value'] ?? '', $assignment['append']);

            return;
        }

        if ($assignment['type'] === 'scalar') {
            $state->setVar($name, $assignment['value'] ?? '', $assignment['append']);

            return;
        }

        $array = $assignment['append'] ? $state->getArray($name) : [];
        $nextIndex = $this->nextIndex($array);
        $elements = [];

        // An unkeyed element goes one past the last numeric index, so (a [5]=b c) puts c at 6
        foreach ($assignment['elements'] ?? [] as [$key, $value]) {
            $key = $key === null ? $nextIndex : $this->arrayIndex($name, $key);
            $elements[$key] = $value;

            if (is_int($key)) {
                $nextIndex = $key + 1;
            }
        }

        if (! $assignment['append']) {
            $state->setArray($name, $elements);
        }

        foreach ($assignment['append'] ? $elements : [] as $key => $value) {
            $state->setElement($name, $key, $value);
        }
    }

    /** An assignment's `[subscript]`, expanded; whether it's arithmetic waits until the array is known to be indexed */
    public function expandSubscript(string $subscript): int|string
    {
        return $this->normalizeKey($this->wordExpander->expand(new WordNode([new LiteralPart($subscript)])));
    }

    /** @param array<int|string, string> $array */
    private function nextIndex(array $array): int
    {
        $numericKeys = array_filter(array_keys($array), is_int(...));

        if ($numericKeys === []) {
            return 0;
        }

        return max($numericKeys) + 1;
    }

    private function normalizeKey(string $subscript): int|string
    {
        return preg_match('/^-?\d+$/', $subscript) === 1 ? (int) $subscript : $subscript;
    }

    /** An associative array's key as it is, else an arithmetic index counting back from the end when negative. */
    private function arrayIndex(string $name, int|string $key): int|string
    {
        $state = $this->interpreterState;

        if ($state->hasAttribute($state->resolve($name, quiet: true) ?? $name, 'A')) {
            return $key;
        }

        $index = is_int($key) ? $key : $this->arithmeticEvaluator->evaluateOrExit($key);
        $index += $index < 0 ? $this->nextIndex($state->getArray($name)) : 0;

        return $index >= 0 ? $index : throw new AssignmentException(sprintf('bash: %s[%s]: bad array subscript', $name, $key));
    }
}
