<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Cache;

/**
 * Filesystem-based compiled template cache (default).
 *
 * Improvements over the previous inline cache:
 * - Atomic writes via tempnam() + rename() (prevents half-written includes)
 * - OPcache-aware invalidation
 * - Production mode (skip filemtime checks entirely)
 * - Dependency tracking manifest for multi-file invalidation
 */
final class FilesystemViewCache implements ViewCacheInterface
{
    /**
     * @param string $cacheDir   Directory for compiled PHP files
     * @param bool   $checkMtime When false (production), trusts cache entirely — never calls filemtime()
     */
    public function __construct(
        private readonly string $cacheDir,
        private readonly bool $checkMtime = true,
    ) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    public function isFresh(string $name, string $sourcePath): bool
    {
        $compiledPath = $this->getCompiledPath($name, $sourcePath);

        if (!is_file($compiledPath)) {
            return false;
        }

        // Production mode: trust the cache
        if (!$this->checkMtime) {
            return true;
        }

        $compiledMtime = filemtime($compiledPath);
        $sourceMtime = filemtime($sourcePath);

        if ($compiledMtime === false || $sourceMtime === false) {
            return false;
        }

        // Check dependency manifest if it exists
        $depsPath = $this->getDepsPath($name, $sourcePath);
        if (is_file($depsPath)) {
            /** @var array<string, int>|false $deps */
            $deps = include $depsPath;
            if (is_array($deps)) {
                foreach ($deps as $depPath => $depMtime) {
                    if (!is_file($depPath)) {
                        return false;
                    }
                    $currentMtime = filemtime($depPath);
                    if ($currentMtime === false || $currentMtime > $depMtime) {
                        return false;
                    }
                }
            }
        }

        return $sourceMtime <= $compiledMtime;
    }

    public function getCompiledPath(string $name, string $sourcePath): string
    {
        $file = str_replace(['.', '/'], '_', $name) . '_' . md5($sourcePath) . '.php';
        return $this->cacheDir . DIRECTORY_SEPARATOR . $file;
    }

    public function put(string $name, string $sourcePath, string $compiledPhp): string
    {
        $compiledPath = $this->getCompiledPath($name, $sourcePath);

        // Atomic write: write to temp file, then rename
        $tmpPath = tempnam($this->cacheDir, 'ml_');
        if ($tmpPath === false) {
            // Fallback to direct write
            file_put_contents($compiledPath, $compiledPhp);
            $this->invalidateOpcache($compiledPath);
            return $compiledPath;
        }

        file_put_contents($tmpPath, $compiledPhp);
        rename($tmpPath, $compiledPath);
        $this->invalidateOpcache($compiledPath);

        return $compiledPath;
    }

    /**
     * Store dependency manifest for a compiled template.
     *
     * @param array<string, int> $dependencies Map of depPath => mtime
     */
    public function putDependencies(string $name, string $sourcePath, array $dependencies): void
    {
        $depsPath = $this->getDepsPath($name, $sourcePath);
        $content = "<?php\nreturn " . var_export($dependencies, true) . ";\n";
        file_put_contents($depsPath, $content);
    }

    public function forget(string $name): void
    {
        // Without a source path we can't compute exact hash, so glob for matching files
        $prefix = $this->cacheDir . DIRECTORY_SEPARATOR
            . str_replace(['.', '/'], '_', $name) . '_*';

        // Delete compiled PHP files and dependency manifests
        foreach (['php', 'deps.php'] as $ext) {
            $files = glob($prefix . '.' . $ext);
            if ($files === false) {
                continue;
            }
            foreach ($files as $file) {
                $this->invalidateOpcache($file);
                @unlink($file);
            }
        }
    }

    public function flush(): void
    {
        // Delete all compiled PHP files and dependency manifests
        $files = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.{php,deps.php}', GLOB_BRACE);
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            $this->invalidateOpcache($file);
            @unlink($file);
        }
    }

    /**
     * Whether this cache is in production mode (no mtime checks).
     */
    public function isProductionMode(): bool
    {
        return !$this->checkMtime;
    }

    private function getDepsPath(string $name, string $sourcePath): string
    {
        $file = str_replace(['.', '/'], '_', $name) . '_' . md5($sourcePath) . '.deps.php';
        return $this->cacheDir . DIRECTORY_SEPARATOR . $file;
    }

    private function invalidateOpcache(string $path): void
    {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
    }
}
