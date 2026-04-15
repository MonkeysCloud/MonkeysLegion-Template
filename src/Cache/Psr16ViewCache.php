<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 backed template cache adapter.
 *
 * Works with any PSR-16 store (monkeyslegion-cache, Symfony, etc.)
 * Still writes to disk for PHP `include`, but the compiled PHP content
 * and freshness metadata are stored in the PSR-16 store for fast checks.
 */
final class Psr16ViewCache implements ViewCacheInterface
{
    private const KEY_PREFIX = 'mlview:';

    /**
     * @param CacheInterface $store        PSR-16 cache store
     * @param string         $diskCacheDir Disk dir for PHP include files
     * @param int            $defaultTtl   TTL for cached compiled PHP (seconds)
     */
    public function __construct(
        private readonly CacheInterface $store,
        private readonly string $diskCacheDir,
        private readonly int $defaultTtl = 86400,
    ) {
        if (!is_dir($this->diskCacheDir)) {
            mkdir($this->diskCacheDir, 0755, true);
        }
    }

    public function isFresh(string $name, string $sourcePath): bool
    {
        $metaKey = self::KEY_PREFIX . 'meta:' . $this->hashKey($name, $sourcePath);

        /** @var array{mtime: int, compiled_path: string}|null $meta */
        $meta = $this->store->get($metaKey);

        if ($meta === null) {
            return false;
        }

        $sourceMtime = filemtime($sourcePath);
        if ($sourceMtime === false) {
            return false;
        }

        if ($sourceMtime > $meta['mtime']) {
            return false;
        }

        // Ensure the disk file still exists (for include)
        return is_file($meta['compiled_path']);
    }

    public function getCompiledPath(string $name, string $sourcePath): string
    {
        $metaKey = self::KEY_PREFIX . 'meta:' . $this->hashKey($name, $sourcePath);

        /** @var array{mtime: int, compiled_path: string}|null $meta */
        $meta = $this->store->get($metaKey);

        if ($meta !== null && is_file($meta['compiled_path'])) {
            return $meta['compiled_path'];
        }

        // Fallback: compute the path even if not cached
        $file = str_replace(['.', '/'], '_', $name) . '_' . md5($sourcePath) . '.php';
        return $this->diskCacheDir . DIRECTORY_SEPARATOR . $file;
    }

    public function put(string $name, string $sourcePath, string $compiledPhp): string
    {
        $file = str_replace(['.', '/'], '_', $name) . '_' . md5($sourcePath) . '.php';
        $compiledPath = $this->diskCacheDir . DIRECTORY_SEPARATOR . $file;

        // Atomic write to disk
        $tmpPath = tempnam($this->diskCacheDir, 'ml_');
        if ($tmpPath !== false) {
            file_put_contents($tmpPath, $compiledPhp);
            rename($tmpPath, $compiledPath);
        } else {
            file_put_contents($compiledPath, $compiledPhp);
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($compiledPath, true);
        }

        // Store metadata in PSR-16
        $metaKey = self::KEY_PREFIX . 'meta:' . $this->hashKey($name, $sourcePath);
        $sourceMtime = filemtime($sourcePath);

        $this->store->set($metaKey, [
            'mtime' => $sourceMtime !== false ? $sourceMtime : time(),
            'compiled_path' => $compiledPath,
        ], $this->defaultTtl);

        // Also store compiled PHP in cache for potential cross-server sharing
        $phpKey = self::KEY_PREFIX . 'php:' . $this->hashKey($name, $sourcePath);
        $this->store->set($phpKey, $compiledPhp, $this->defaultTtl);

        return $compiledPath;
    }

    public function forget(string $name): void
    {
        // We need to clear all possible hashes for this name
        // Since we can't enumerate PSR-16 keys, delete the known pattern
        $pattern = $this->diskCacheDir . DIRECTORY_SEPARATOR
            . str_replace(['.', '/'], '_', $name) . '_*.php';
        $files = glob($pattern);
        if ($files !== false) {
            foreach ($files as $file) {
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($file, true);
                }
                @unlink($file);
            }
        }
    }

    public function flush(): void
    {
        // Only delete MLView disk files — do NOT call $this->store->clear()
        // as the PSR-16 store may be shared with other application concerns
        // (sessions, rate limits, etc.)
        $files = glob($this->diskCacheDir . DIRECTORY_SEPARATOR . '*.php');
        if ($files !== false) {
            foreach ($files as $file) {
                // Delete the corresponding PSR-16 metadata keys
                $basename = pathinfo($file, PATHINFO_FILENAME);
                $this->store->delete(self::KEY_PREFIX . 'meta:' . $basename);
                $this->store->delete(self::KEY_PREFIX . 'php:' . $basename);

                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($file, true);
                }
                @unlink($file);
            }
        }
    }

    /**
     * Get the underlying PSR-16 store.
     */
    public function getStore(): CacheInterface
    {
        return $this->store;
    }

    private function hashKey(string $name, string $sourcePath): string
    {
        return md5($name . ':' . $sourcePath);
    }
}
