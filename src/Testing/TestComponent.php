<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Testing;

/**
 * Render a component in isolation for testing.
 *
 * Usage:
 *   TestComponent::make('alert', ['type' => 'error'])
 *       ->withSlot('default', '<p>Error occurred</p>')
 *       ->assertSee('Error occurred');
 */
final class TestComponent
{
    private string $name;

    /** @var array<string, mixed> */
    private array $props;

    /** @var array<string, string> */
    private array $slots = [];

    private ?string $renderedOutput = null;

    /**
     * @param array<string, mixed> $props
     */
    private function __construct(string $name, array $props = [])
    {
        $this->name = $name;
        $this->props = $props;
    }

    /**
     * Create a test component instance.
     *
     * @param array<string, mixed> $props
     */
    public static function make(string $name, array $props = []): self
    {
        return new self($name, $props);
    }

    /**
     * Add a slot to the component.
     */
    public function withSlot(string $name, string $content): self
    {
        $this->slots[$name] = $content;
        return $this;
    }

    /**
     * Set a prop value.
     */
    public function withProp(string $key, mixed $value): self
    {
        $this->props[$key] = $value;
        return $this;
    }

    /**
     * Get the component name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get all props.
     *
     * @return array<string, mixed>
     */
    public function getProps(): array
    {
        return $this->props;
    }

    /**
     * Get all slots.
     *
     * @return array<string, string>
     */
    public function getSlots(): array
    {
        return $this->slots;
    }

    /**
     * Get a specific prop.
     */
    public function getProp(string $key, mixed $default = null): mixed
    {
        return $this->props[$key] ?? $default;
    }

    /**
     * Set the rendered output (called by a test renderer).
     */
    public function setRenderedOutput(string $output): void
    {
        $this->renderedOutput = $output;
    }

    /**
     * Get the rendered output.
     */
    public function getRenderedOutput(): ?string
    {
        return $this->renderedOutput;
    }

    /**
     * Create a TestView from this component's output (for assertions).
     */
    public function toTestView(): TestView
    {
        return TestView::fromRendered(
            'component:' . $this->name,
            $this->renderedOutput ?? '',
            $this->props,
        );
    }

    /**
     * Assert the rendered output contains the given text.
     */
    public function assertSee(string $text): self
    {
        $this->toTestView()->assertSee($text);
        return $this;
    }

    /**
     * Assert the rendered output does NOT contain the given text.
     */
    public function assertDontSee(string $text): self
    {
        $this->toTestView()->assertDontSee($text);
        return $this;
    }
}
