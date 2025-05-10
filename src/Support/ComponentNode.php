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
}