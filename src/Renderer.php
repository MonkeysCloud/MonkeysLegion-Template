<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use RuntimeException;

/**
 * Renders MLView templates (parses, compiles, caches, and executes)
 */
final class Renderer
{
    private Parser   $parser;
    private Compiler $compiler;
    private Loader   $loader;
    private bool     $cacheEnabled;
    private string   $cacheDir;

    /**
     * @param Parser   $parser        Template parser (components, slots)
     * @param Compiler $compiler      Template compiler (echoes, directives)
     * @param Loader   $loader        Locates source + compiled files
     * @param bool     $cacheEnabled  Toggle on-disk caching (default true)
     * @param string   $cacheDir      Directory for cached templates (default var/cache/views)
     */
    public function __construct(
        Parser   $parser,
        Compiler $compiler,
        Loader   $loader,
        bool     $cacheEnabled = true,
        string   $cacheDir     = ''
    ) {
        $this->parser       = $parser;
        $this->compiler     = $compiler;
        $this->loader       = $loader;
        $this->cacheEnabled = $cacheEnabled;
        $this->cacheDir     = $cacheDir !== ''
            ? rtrim($cacheDir, DIRECTORY_SEPARATOR)
            : base_path('var/cache/views');
    }

    /**
     * Render a named template with given data.
     *
     * @param string $name Template name (dot-notated path without extension)
     * @param array  $data Variables to extract into template scope
     * @return string      Rendered HTML output
     * @throws RuntimeException on missing source file
     */
    public function render(string $name, array $data = []): string
    {
        $sourcePath   = $this->loader->getSourcePath($name);
        $compiledPath = $this->getCompiledPath($name);

        if (! is_file($sourcePath)) {
            throw new RuntimeException("Template source not found: {$sourcePath}");
        }

        if ($this->cacheEnabled) {
            // ensure cache directory exists
            if (! is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0755, true);
            }

            // compile if missing or stale
            if (! is_file($compiledPath)
                || filemtime($sourcePath) > filemtime($compiledPath)
            ) {
                $raw  = file_get_contents($sourcePath);
                $ast  = $this->parser->parse($raw);
                $php  = $this->compiler->compile($ast, $sourcePath);
                file_put_contents($compiledPath, $php);
            }

            extract($data, EXTR_SKIP);
            ob_start();
            include $compiledPath;
            return ob_get_clean();
        }

        // cache disabled → compile on the fly and eval
        $raw = file_get_contents($sourcePath);
        $ast = $this->parser->parse($raw);
        $php = $this->compiler->compile($ast, $sourcePath);

        // strip PHP tags for eval mode
        if (str_starts_with($php, '<?php')) {
            $php = substr($php, strlen('<?php'));
        }
        if (str_ends_with($php, '?>')) {


            $php = substr($php, 0, -strlen('?>'));
        }

        extract($data, EXTR_SKIP);
        ob_start();
        eval($php);
        return ob_get_clean();
    }

    /**
     * Determine file path for compiled template
     */
    private function getCompiledPath(string $name): string
    {
        $filename = str_replace(['.', '/'], '_', $name) . '.php';
        return $this->cacheDir . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Clear all compiled template files.
     */
    public function clearCache(): void
    {
        if (! is_dir($this->cacheDir)) {
            return;
        }
        foreach (glob($this->cacheDir . '/*.php') as $file) {
            @unlink($file);
        }
    }

}