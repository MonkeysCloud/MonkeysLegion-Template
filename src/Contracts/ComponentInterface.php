<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Contracts;

/**
 * Interface for class-based view components.
 *
 * Components encapsulate reusable UI logic with typed props,
 * computed properties, and scoped rendering.
 */
interface ComponentInterface
{
    /**
     * Get the view name or path for this component.
     */
    public function render(): string;

    /**
     * Get the data to pass to the component view.
     *
     * @return array<string, mixed>
     */
    public function data(): array;

    /**
     * Resolve the full view path for this component.
     */
    public function resolveView(): string;

    /**
     * Set attributes on the component.
     *
     * @param array<string, mixed> $attributes
     */
    public function withAttributes(array $attributes): static;
}
