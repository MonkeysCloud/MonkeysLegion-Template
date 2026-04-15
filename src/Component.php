<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use MonkeysLegion\Template\Contracts\ComponentInterface;
use MonkeysLegion\Template\Support\AttributeBag;

/**
 * Abstract base class for class-based view components.
 *
 * Components encapsulate reusable UI logic. Extend this class and implement
 * `render()` to return the view name. Public properties and `get*` methods
 * are automatically available in the component template.
 *
 * Supports PHP 8.4 property hooks for computed properties.
 */
abstract class Component implements ComponentInterface
{
    /** @var array<string, mixed> */
    private array $__attributes = [];

    private ?AttributeBag $__attributeBag = null;

    /**
     * Get the view name for this component.
     * Override in subclasses to specify the view.
     */
    abstract public function render(): string;

    /**
     * Get computed data for the component view.
     *
     * Merges public properties and public `get*()` methods as computed values.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $data = [];

        // Extract public properties
        $reflection = new \ReflectionClass($this);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            // Skip internal properties
            if (str_starts_with($name, '__')) {
                continue;
            }
            if ($prop->isInitialized($this)) {
                $data[$name] = $prop->getValue($this);
            }
        }

        // Extract computed properties from public get* methods
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (str_starts_with($name, 'get') && $name !== 'getAttributes'
                && $method->getNumberOfRequiredParameters() === 0
                && !$method->isAbstract()
            ) {
                $propName = lcfirst(substr($name, 3));
                if (!isset($data[$propName])) {
                    $data[$propName] = $method->invoke($this);
                }
            }
        }

        return $data;
    }

    /**
     * Resolve the view path for this component.
     */
    public function resolveView(): string
    {
        return $this->render();
    }

    /**
     * Set HTML attributes on the component.
     *
     * @param array<string, mixed> $attributes
     */
    public function withAttributes(array $attributes): static
    {
        $this->__attributes = $attributes;
        $this->__attributeBag = new AttributeBag($attributes);

        return $this;
    }

    /**
     * Get the attribute bag.
     */
    public function getAttributes(): AttributeBag
    {
        return $this->__attributeBag ??= new AttributeBag($this->__attributes);
    }
}
