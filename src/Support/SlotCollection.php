<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Support;

/**
 * Manages component slots with checking, default values, and attribute support.
 *
 * Usage in components:
 * {{ $slot }}                    - Default slot
 * {{ $slots->header }}           - Named slot
 * @if($slots->has('footer'))     - Check if slot exists
 * {{ $slots->footer ?? 'Default' }} - With fallback
 */
class SlotCollection
{
    private array $slots = [];
    private mixed $defaultSlot = null;

    public function __construct(array $slots = [], mixed $defaultSlot = null)
    {
        $this->slots = $slots;
        $this->defaultSlot = $defaultSlot;
    }

    /**
     * Get a named slot's content
     */
    public function get(string $name, mixed $default = ''): string
    {
        if (!isset($this->slots[$name])) {
            return is_callable($default) ? $default() : (string)$default;
        }

        $slot = $this->slots[$name];

        if (is_callable($slot)) {
            return (string)$slot();
        }

        return (string)$slot;
    }

    /**
     * Check if a named slot exists and has content
     */
    public function has(string $name): bool
    {
        if (!isset($this->slots[$name])) {
            return false;
        }

        $content = $this->get($name);
        return !empty(trim($content));
    }

    /**
     * Check if a slot is empty
     */
    public function isEmpty(string $name): bool
    {
        return !$this->has($name);
    }

    /**
     * Get the default slot content
     */
    public function getDefault(mixed $fallback = ''): string
    {
        if ($this->defaultSlot === null) {
            return is_callable($fallback) ? $fallback() : (string)$fallback;
        }

        if (is_callable($this->defaultSlot)) {
            return (string)($this->defaultSlot)();
        }

        return (string)$this->defaultSlot;
    }

    /**
     * Check if default slot has content
     */
    public function hasDefault(): bool
    {
        if ($this->defaultSlot === null) {
            return false;
        }

        $content = $this->getDefault();
        return !empty(trim($content));
    }

    /**
     * Get all slot names
     */
    public function names(): array
    {
        return array_keys($this->slots);
    }

    /**
     * Get all slots as array
     */
    public function all(): array
    {
        return $this->slots;
    }

    /**
     * Set a slot
     */
    public function set(string $name, mixed $content): self
    {
        $this->slots[$name] = $content;
        return $this;
    }

    /**
     * Magic getter for slot access: $slots->header
     */
    public function __get(string $name): string
    {
        return $this->get($name);
    }

    /**
     * Magic isset for slot checking: isset($slots->header)
     */
    public function __isset(string $name): bool
    {
        return $this->has($name);
    }

    /**
     * Convert to string (returns default slot)
     */
    public function __toString(): string
    {
        return $this->getDefault();
    }

    /**
     * Helper to render slot with wrapper if content exists
     *
     * @param string $name Slot name
     * @param string $wrapper HTML wrapper with {slot} placeholder
     * Example: $slots->wrapped('header', '<div class="header">{slot}</div>')
     */
    public function wrapped(string $name, string $wrapper, mixed $default = ''): string
    {
        if (!$this->has($name)) {
            return is_callable($default) ? $default() : (string)$default;
        }

        $content = $this->get($name);
        return str_replace('{slot}', $content, $wrapper);
    }

    /**
     * Render multiple slots in order
     */
    public function render(array $names, string $separator = ''): string
    {
        $output = [];

        foreach ($names as $name) {
            if ($this->has($name)) {
                $output[] = $this->get($name);
            }
        }

        return implode($separator, $output);
    }

    /**
     * Create a slot collection from component data
     */
    public static function fromArray(array $data): self
    {
        $defaultSlot = $data['__default'] ?? $data['slotContent'] ?? null;
        unset($data['__default'], $data['slotContent']);

        return new self($data, $defaultSlot);
    }
}