<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Support;

/**
 * AST node representing a @slot('name') ... @endslot block.
 */
class SlotNode
{
    /** @var string|string[] Nested content or nested nodes */
    private array|string $content;

    public function __construct(
        public readonly string $name,
        array|string $content
    ) {
        $this->content = $content;
    }

    /**
     * @return array|string
     */
    public function getContent(): array|string
    {
        return $this->content;
    }

    /**
     * Replace the content (used by parser transforms).
     */
    public function setContent(array|string $content): void
    {
        $this->content = $content;
    }

    /**
     * Export to associative array for debug.
     */
    public function toArray(): array
    {
        return [
            'slot'    => $this->name,
            'content' => $this->content,
        ];
    }
}