<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Cache;

/**
 * Contract for the template compiled-cache layer.
 *
 * Decouples the Renderer from any specific caching strategy.
 * Default: FilesystemViewCache (disk). Optional: Psr16ViewCache (Redis/etc.)
 */
interface ViewCacheInterface
{
    /**
     * Check if a compiled version exists and is fresh for the given source.
     */
    public function isFresh(string $name, string $sourcePath): bool;

    /**
     * Get the on-disk path for the compiled PHP (required for `include`).
     */
    public function getCompiledPath(string $name, string $sourcePath): string;

    /**
     * Store compiled PHP and return the on-disk path.
     */
    public function put(string $name, string $sourcePath, string $compiledPhp): string;

    /**
     * Invalidate a single template.
     */
    public function forget(string $name): void;

    /**
     * Invalidate all compiled templates.
     */
    public function flush(): void;
}
