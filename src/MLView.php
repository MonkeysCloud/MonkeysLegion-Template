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
    ) {}

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
        // Execute and return HTML
        return $this->renderer->render($name, $data);
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
