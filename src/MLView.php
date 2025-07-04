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
        // 1) Resolve canonical paths through the Loader API
        $source   = $this->loader->getSourcePath($name);
        $compiled = $this->loader->getCompiledPath($name);

        // 2) (Re)compile when the source is newer than its cache
        if (! is_file($compiled) || filemtime($source) > filemtime($compiled)) {
            $this->loader->ensureCompiledDir($compiled);
            $compiledCode = $this->compiler->compile(
                file_get_contents($source),
                $source
            );
            file_put_contents($compiled, $compiledCode);
        }

        // 3) Execute and return HTML
        return $this->renderer->render($compiled, $data);
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