<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use RuntimeException;

/**
 * Main view engine for MonkeysLegion.
 *
 * - Locates raw template files via Loader
 * - Compiles them to cached PHP via Compiler
 * - Renders compiled PHP with data via Renderer
 */
class MLView
{
    /**
     * @param Loader   $loader    Locates template files by name
     * @param Compiler $compiler  Converts template source into PHP code
     * @param Renderer $renderer  Executes compiled PHP and captures output
     * @param string   $cacheDir  Directory where compiled templates are stored
     */
    public function __construct(
        /** @phpstan-ignore property.onlyWritten */
        private Loader $loader,
        /** @phpstan-ignore property.onlyWritten */
        private Compiler $compiler,
        private Renderer $renderer,
        private string $cacheDir,
        array $config = []
    ) {
        if (!empty($config['strict_mode'])) {
            $this->compiler->setStrictMode(true);
        }
        // Ensure Renderer shares the same registry as Compiler
        $this->renderer->setRegistry($this->compiler->getRegistry());
    }

    /**
     * Render a template by name with the provided data.
     *
     * @param string $name Template name (e.g. 'home' → resources/views/home.ml.php)
     * @param array<string, mixed> $data Variables to extract into template scope
     * @return string      Rendered HTML
     * @throws RuntimeException on missing template or compile errors
     */
    public function render(string $name, array $data = []): string
    {
        // Execute and return HTML
        return $this->renderer->render($name, $data);
    }

    /**
     * Clear all compiled templates from the cache directory.
     */
    public function clearCache(): void
    {
        $files = glob(rtrim($this->cacheDir, '/\\') . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            @unlink($file);
        }
    }

    public function addDirective(string $name, callable $handler): void
    {
        $this->compiler->getRegistry()->addDirective($name, $handler);
    }

    public function addFilter(string $name, callable $handler): void
    {
        $this->compiler->getRegistry()->addFilter($name, $handler);
    }

    public function addNamespace(string $namespace, string $hint): void
    {
        $this->loader->addNamespace($namespace, $hint);
    }

    public function addViewPath(string $path): void
    {
        $this->loader->addPath($path);
    }

    public function prependViewPath(string $path): void
    {
        $this->loader->prependPath($path);
    }

    /**
     * Set the current theme.
     * This assumes themes are located in a 'themes' directory within the view paths?
     * Or we prepend a specific path for the theme.
     * 
     * @param string $themeName The name of the theme folder (e.g. 'dark')
     * @param string|null $baseThemesPath The base path where themes are stored. If null, assumes 'resources/themes'.
     */
    public function setTheme(string $themeName, ?string $baseThemesPath = null): void
    {
        $baseThemesPath = $baseThemesPath ?? (function_exists('resource_path') ? resource_path('themes') : 'resources/themes');
        
        $themePath = rtrim($baseThemesPath, '/\\') . DIRECTORY_SEPARATOR . $themeName;
        
        // We PREPEND the theme path so it takes precedence over default paths.
        $this->loader->prependPath($themePath);
    }
}
