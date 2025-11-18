<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Support;

/**
 * Manages component attributes with merging, spreading, and conditional classes.
 *
 * Usage in components:
 * <div {{ $attrs }}>...</div>
 * <div {{ $attrs->merge(['class' => 'btn']) }}>...</div>
 * <div {{ $attrs->only(['id', 'data-*']) }}>...</div>
 * <div {{ $attrs->except(['class']) }}>...</div>
 */
class AttributeBag
{
    private array $attributes = [];

    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    /**
     * Get all attributes as array
     */
    public function all(): array
    {
        return $this->attributes;
    }

    /**
     * Get a specific attribute value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Check if attribute exists
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /**
     * Set an attribute
     */
    public function set(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Merge attributes with defaults, with special handling for 'class'
     */
    public function merge(array $defaults = []): self
    {
        $merged = $defaults;

        foreach ($this->attributes as $key => $value) {
            if ($key === 'class' && isset($defaults['class'])) {
                // Merge classes instead of replacing
                $merged['class'] = $this->mergeClasses($defaults['class'], $value);
            } else {
                // Override other attributes
                $merged[$key] = $value;
            }
        }

        return new self($merged);
    }

    /**
     * Get only specified attributes (supports wildcards)
     */
    public function only(array $keys): self
    {
        $filtered = [];

        foreach ($keys as $pattern) {
            if (str_contains($pattern, '*')) {
                // Wildcard matching (e.g., 'data-*')
                $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/';
                foreach ($this->attributes as $key => $value) {
                    if (preg_match($regex, $key)) {
                        $filtered[$key] = $value;
                    }
                }
            } elseif (isset($this->attributes[$pattern])) {
                $filtered[$pattern] = $this->attributes[$pattern];
            }
        }

        return new self($filtered);
    }

    /**
     * Get all attributes except specified ones (supports wildcards)
     */
    public function except(array $keys): self
    {
        $filtered = $this->attributes;

        foreach ($keys as $pattern) {
            if (str_contains($pattern, '*')) {
                // Wildcard matching
                $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/';
                foreach (array_keys($filtered) as $key) {
                    if (preg_match($regex, $key)) {
                        unset($filtered[$key]);
                    }
                }
            } else {
                unset($filtered[$pattern]);
            }
        }

        return new self($filtered);
    }

    /**
     * Get attributes filtered by prefix (helper for data-*, aria-*, etc.)
     */
    public function withPrefix(string $prefix): self
    {
        return $this->only([$prefix . '*']);
    }

    /**
     * Convert to HTML attribute string
     */
    public function toHtml(): string
    {
        if (empty($this->attributes)) {
            return '';
        }

        $parts = [];
        foreach ($this->attributes as $key => $value) {
            if (is_bool($value)) {
                // Boolean attributes (disabled, readonly, etc.)
                if ($value) {
                    $parts[] = $key;
                }
            } elseif ($value !== null && $value !== '') {
                // Regular attributes
                $escaped = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
                $parts[] = "{$key}=\"{$escaped}\"";
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Magic method to convert to string
     */
    public function __toString(): string
    {
        return $this->toHtml();
    }

    /**
     * Merge CSS classes intelligently
     */
    private function mergeClasses(string $default, string $override): string
    {
        $defaultClasses = array_filter(explode(' ', $default));
        $overrideClasses = array_filter(explode(' ', $override));

        // Merge and deduplicate
        $merged = array_unique(array_merge($defaultClasses, $overrideClasses));

        return implode(' ', $merged);
    }

    /**
     * Create conditional classes (Alpine.js/Vue style)
     *
     * @param array $classes Array of class names or conditions
     * Example: ['base-class', $active ? 'active' : '', 'btn' => $isPrimary]
     */
    public static function conditional(array $classes): string
    {
        $result = [];

        foreach ($classes as $key => $value) {
            if (is_int($key)) {
                // Simple class name: ['active', 'btn']
                if ($value) {
                    $result[] = $value;
                }
            } else {
                // Conditional: ['active' => $isActive]
                if ($value) {
                    $result[] = $key;
                }
            }
        }

        return implode(' ', array_filter($result));
    }

    /**
     * Helper to process :class directive
     */
    public static function processClassDirective(string $expression): string
    {
        // This will be used by the Parser to convert :class="[...]" syntax
        return "<?= \\MonkeysLegion\\Template\\Support\\AttributeBag::conditional({$expression}) ?>";
    }
}