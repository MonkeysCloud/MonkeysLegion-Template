<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

/**
 * Lightweight function components — closures or static methods.
 *
 * Zero-class-overhead alternative to class-based components.
 * Registered via closure or #[FunctionComponent] attribute.
 */
final class FunctionComponent
{
    /** @var array<string, callable> */
    private static array $components = [];

    /**
     * Register a function component.
     */
    public static function register(string $name, callable $handler): void
    {
        self::$components[$name] = $handler;
    }

    /**
     * Check if a function component is registered.
     */
    public static function has(string $name): bool
    {
        return isset(self::$components[$name]);
    }

    /**
     * Render a function component.
     *
     * @param array<string, mixed> $attributes
     */
    public static function render(string $name, array $attributes = []): string
    {
        if (!isset(self::$components[$name])) {
            throw new \RuntimeException("Function component [{$name}] is not registered.");
        }

        $handler = self::$components[$name];

        // Resolve named parameters from attributes
        $reflection = new \ReflectionFunction(\Closure::fromCallable($handler));
        $args = [];

        foreach ($reflection->getParameters() as $param) {
            $paramName = $param->getName();

            if (isset($attributes[$paramName])) {
                $args[] = $attributes[$paramName];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = null;
            }
        }

        return (string) call_user_func_array($handler, $args);
    }

    /**
     * Get all registered function component names.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::$components);
    }

    /**
     * Unregister a function component (useful in tests).
     */
    public static function unregister(string $name): void
    {
        unset(self::$components[$name]);
    }

    /**
     * Clear all registered components (useful in tests).
     */
    public static function clear(): void
    {
        self::$components = [];
    }
}
