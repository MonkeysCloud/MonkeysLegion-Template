<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use MonkeysLegion\Template\Contracts\LoaderInterface;
use RuntimeException;

/**
 * Responsible for locating raw template files and compiled cache files by name.
 */
class Loader implements LoaderInterface
{
    /** @var array<int, string> */
    private array $paths = [];

    /** @var array<string, string> */
    private array $namespaces = [];

    private string $cachePath;
    private string $templateExtension;

    /**
     * @param string|list<string> $sourcePath   Directory or directories where raw templates reside
     * @param string              $cachePath    Directory where compiled templates are stored
     * @param string              $templateExtension Extension of raw template files (default ".ml.php")
     */
    public function __construct(
        string|array $sourcePath = [],
        string $cachePath = '',
        string $templateExtension = '.ml.php'
    ) {
        $this->paths = is_array($sourcePath) ? $sourcePath : [$sourcePath];
        // Normalize paths
        $this->paths = array_map(fn($p) => rtrim($p, '/\\'), $this->paths);

        $this->cachePath         = rtrim($cachePath, '/\\');
        $this->templateExtension = $templateExtension;
    }

    public function addPath(string $path): void
    {
        $this->paths[] = rtrim($path, '/\\');
    }

    public function prependPath(string $path): void
    {
        array_unshift($this->paths, rtrim($path, '/\\'));
    }

    public function addNamespace(string $namespace, string $path): void
    {
        $this->namespaces[$namespace] = rtrim($path, '/\\');
    }

    /**
     * Get the full filesystem path for a given template source.
     * Supports namespaces: 'namespace::view.name'
     * E.g. 'users.index' → '/views/users/index.ml.php'
     *      'admin::dashboard' → '/vendor/admin/dashboard.ml.php'
     *
     * @param string $name  Dot-notated template name or namespaced name
     * @return string       Full file path
     * @throws RuntimeException if the file does not exist
     */
    public function getSourcePath(string $name): string
    {
        if (str_contains($name, '::')) {
            return $this->resolveNamespacedPath($name);
        }

        $relative = str_replace('.', DIRECTORY_SEPARATOR, $name) . $this->templateExtension;

        foreach ($this->paths as $path) {
            $fullPath = $path . DIRECTORY_SEPARATOR . $relative;
            if (is_file($fullPath)) {
                return $fullPath;
            }
        }

        throw new RuntimeException("Template source not found: {$name} (looked in: " . implode(', ', $this->paths) . ")");
    }

    private function resolveNamespacedPath(string $name): string
    {
        [$namespace, $view] = explode('::', $name, 2);

        if (!isset($this->namespaces[$namespace])) {
            throw new RuntimeException("Template namespace not defined: {$namespace}");
        }

        // Ideally we should also check if the NAMESPACE is overridden in the main paths (theming namespaces).
        // Convention: resources/views/vendor/{namespace}/{view}
        // Iterate main paths to check for overrides
        $relativeOverride = 'vendor' . DIRECTORY_SEPARATOR . $namespace . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . $this->templateExtension;

        foreach ($this->paths as $path) {
             $overridePath = $path . DIRECTORY_SEPARATOR . $relativeOverride;
             if (is_file($overridePath)) {
                 return $overridePath;
             }
        }

        // Fallback to registered namespace path
        $relative = str_replace('.', DIRECTORY_SEPARATOR, $view) . $this->templateExtension;
        $fullPath = $this->namespaces[$namespace] . DIRECTORY_SEPARATOR . $relative;

        if (is_file($fullPath)) {
            return $fullPath;
        }

        throw new RuntimeException("Template source not found for namespaced view: {$name}");
    }

    /**
     * Get the full filesystem path for the compiled template.
     * E.g. 'users.index' → '/cache/users/index.php'
     *
     * @param string $name  Dot-notated template name
     * @return string       Compiled file path (may not yet exist)
     */
    public function getCompiledPath(string $name): string
    {
        $relative = str_replace('.', DIRECTORY_SEPARATOR, $name) . '.php';
        return $this->cachePath . DIRECTORY_SEPARATOR . $relative;
    }

    /**
     * (Optional) Create the compiled directory if missing.
     *
     * @param string $path  File path to ensure directory exists for
     */
    public function ensureCompiledDir(string $path): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
