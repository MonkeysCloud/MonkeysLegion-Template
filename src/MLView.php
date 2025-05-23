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
        private Loader   $loader,
        private Compiler $compiler,
        private Renderer $renderer,
        private string   $cacheDir
    ) {
        // Ensure the cache directory exists
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Render a template by name with the provided data.
     *
     * @param string $name Template name (e.g. 'home' → resources/views/home.ml.php)
     * @param array  $data Variables to extract into template scope
     * @return string      Rendered HTML
     * @throws RuntimeException on missing template or compile errors
     */
    public function render(string $name, array $data = []): string
    {
        // 1) Locate the raw template file
        $templatePath = $this->loader->getPath($name);
        if (!is_file($templatePath)) {
            throw new RuntimeException("Template not found: {$name}");
        }

        // 2) Compile if out-of-date
        $cached = $this->compileIfNeeded($templatePath);

        // 3) Execute and return output
        return $this->renderer->render($cached, $data);
    }

    /**
     * Compile the template source if its cached version is missing or stale.
     */
    private function compileIfNeeded(string $templatePath): string
    {
        $cacheFile = $this->getCacheFile($templatePath);

        // Compile when cache missing or source changed
        if (!is_file($cacheFile) || filemtime($templatePath) > filemtime($cacheFile)) {
            $source = file_get_contents($templatePath);
            $compiled = $this->compiler->compile($source, $templatePath);
            file_put_contents($cacheFile, $compiled);
        }

        return $cacheFile;
    }

    /**
     * Generate a unique cache filename for a given template path.
     */
    private function getCacheFile(string $templatePath): string
    {
        $baseDir = $this->loader->getBasePath();
        $relative = ltrim(str_replace($baseDir, '', $templatePath), '/\\');
        $filename = preg_replace('/[^a-zA-Z0-9_]/', '_', $relative) . '.php';
        return rtrim($this->cacheDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Clear all compiled templates from the cache directory.
     */
    public function clearCache(): void
    {
        $files = glob(rtrim($this->cacheDir, '/\\') . DIRECTORY_SEPARATOR . '*.php');
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}