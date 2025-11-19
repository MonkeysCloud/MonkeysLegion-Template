<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Support;

/**
 * Simple AST node representing a <x-component> tag in MLView templates.
 */
class ComponentNode
{
    /** @var self[] Children inside the component */
    private array $children = [];

    public function __construct(
        public readonly string $name,           // component name, without <x- > prefix
        public readonly array  $attributes = [] // key => value pairs as parsed from tag
    ) {}

    /**
     * Append a child node (raw string / ComponentNode / SlotNode).
     */
    public function addChild(self|string $child): void
    {
        $this->children[] = $child;
    }

    /**
     * @return list<self|string>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * Recursively export the node tree to array (useful for debugging).
     */
    public function toArray(): array
    {
        return [
            'name'       => $this->name,
            'attributes' => $this->attributes,
            'children'   => array_map(
                fn($c) => $c instanceof self ? $c->toArray() : $c,
                $this->children
            ),
        ];
    }

    /**
     * Normalize attributes into props for the compiler.
     *
     * - title="foo"           → ['title' => ['type' => 'string',     'value' => 'foo']]
     * - :tags="['a','b']"     → ['tags'  => ['type' => 'expression', 'value' => "['a','b']"]]
     *
     * The compiler should use this instead of $this->attributes directly.
     *
     * @return array<string, array{type: 'string'|'expression', value: mixed}>
     */
    public function getNormalizedProps(): array
    {
        $props = [];

        foreach ($this->attributes as $rawName => $rawValue) {
            if ($rawName === null || $rawName === '') {
                continue;
            }

            // Dynamic prop: :foo="expression"
            if ($rawName[0] === ':') {
                $name = substr($rawName, 1); // ':tags' → 'tags'

                $props[$name] = [
                    'type'  => 'expression',
                    'value' => $rawValue,
                ];
                continue;
            }

            // Static prop: title="string"
            $props[$rawName] = [
                'type'  => 'string',
                'value' => $rawValue,
            ];
        }

        return $props;
    }
}
