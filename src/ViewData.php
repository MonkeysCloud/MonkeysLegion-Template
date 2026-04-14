<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

/**
 * Fluent view data object for deferred rendering.
 *
 * Returned by MLView::make() — allows chaining data, sharing,
 * and deferring rendering until __toString or render() is called.
 */
final class ViewData
{
    /** @var array<string, mixed> */
    private array $data = [];

    private string $name;

    private ?Renderer $renderer;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(string $name, array $data = [], ?Renderer $renderer = null)
    {
        $this->name = $name;
        $this->data = $data;
        $this->renderer = $renderer;
    }

    /**
     * Static factory for creating ViewData instances.
     *
     * @param array<string, mixed> $data
     */
    public static function make(string $name, array $data = [], ?Renderer $renderer = null): self
    {
        return new self($name, $data, $renderer);
    }

    /**
     * Add a key-value pair to the view data.
     */
    public function with(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * Merge an array of data into the view data.
     *
     * @param array<string, mixed> $data
     */
    public function withData(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    /**
     * Get the view name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get all view data.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get a specific data value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Check if a data key exists.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Render the view.
     */
    public function render(): string
    {
        if ($this->renderer === null) {
            throw new \RuntimeException('Cannot render ViewData without a Renderer.');
        }

        return $this->renderer->render($this->name, $this->data);
    }

    /**
     * Convert to string (renders the view).
     */
    public function __toString(): string
    {
        try {
            return $this->render();
        } catch (\Throwable) {
            return '';
        }
    }
}
