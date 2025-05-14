<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use RuntimeException;

/**
 * Renders MLView templates (parses, compiles, caches, and executes)
 */
final class Renderer
{
    public function __construct(
        private Parser   $parser,
        private Compiler $compiler,
        private Loader   $loader
    ) {}

    /**
     * Render a named template with given data.
     *
     * @param string $name  Template name (relative path without extension)
     * @param array  $data  Variables to extract into template scope
     * @return string       Rendered HTML output
     * @throws RuntimeException on missing source file
     */
    public function render(string $name, array $data = []): string
    {
        // 1) Locate source and compiled paths
        $sourcePath   = $this->loader->getSourcePath($name);
        $compiledPath = $this->loader->getCompiledPath($name);

        if (! is_file($sourcePath)) {
            throw new RuntimeException("Template source not found: {$name}");
        }

        // 2) Compile if compiled missing or stale
        if (! is_file($compiledPath)
            || filemtime($compiledPath) < filemtime($sourcePath)
        ) {
            $raw   = file_get_contents($sourcePath);
            $ast   = $this->parser->parse($raw);
            $php   = $this->compiler->compile($ast, $sourcePath);
            // Ensure compiled directory exists
            @mkdir(dirname($compiledPath), 0755, true);
            file_put_contents($compiledPath, $php);
        }

        // 3) Extract data and include compiled template
        extract($data, EXTR_SKIP);
        ob_start();
        include $compiledPath;
        return ob_get_clean();
    }
}