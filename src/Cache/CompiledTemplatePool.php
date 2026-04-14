<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Cache;

/**
 * In-memory per-request compiled template pool.
 *
 * Eliminates duplicate filemtime() + is_file() calls when the same
 * template is rendered multiple times in a single request (loops,
 * partials, components).
 */
final class CompiledTemplatePool
{
    /** @var array<string, string> name => compiledPath */
    private array $paths = [];

    /** @var array<string, float> name => resolvedMtime */
    private array $mtimes = [];

    /** @var int Cache hit counter for observability */
    private int $hits = 0;

    /** @var int Cache miss counter for observability */
    private int $misses = 0;

    /**
     * Check if a compiled template is in the pool.
     */
    public function has(string $name): bool
    {
        return isset($this->paths[$name]);
    }

    /**
     * Get the cached compiled path.
     */
    public function getPath(string $name): string
    {
        $this->hits++;
        return $this->paths[$name];
    }

    /**
     * Store a compiled path with its source mtime.
     */
    public function put(string $name, string $path, float $mtime): void
    {
        $this->misses++;
        $this->paths[$name] = $path;
        $this->mtimes[$name] = $mtime;
    }

    /**
     * Get the stored mtime for a template.
     */
    public function getMtime(string $name): ?float
    {
        return $this->mtimes[$name] ?? null;
    }

    /**
     * Remove a specific template from the pool.
     */
    public function forget(string $name): void
    {
        unset($this->paths[$name], $this->mtimes[$name]);
    }

    /**
     * Clear the entire pool.
     */
    public function clear(): void
    {
        $this->paths = [];
        $this->mtimes = [];
    }

    /**
     * Get pool statistics.
     *
     * @return array{hits: int, misses: int, size: int}
     */
    public function getStats(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'size' => count($this->paths),
        ];
    }
}
