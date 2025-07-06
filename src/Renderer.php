<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use RuntimeException;
use Throwable;

/**
 * Renders MLView templates (parses, compiles, caches, and executes)
 * with support for layout inheritance via @extends, @section, and @yield.
 */
final class Renderer
{
    private Parser   $parser;
    private Compiler $compiler;
    private Loader   $loader;
    private bool     $cacheEnabled;
    private string   $cacheDir;

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
     * Handles @extends, @section, and @yield.
     *
     * @param string $name Template key (e.g., 'home')
     * @param array  $data Variables to extract into template scope
     * @return string Rendered HTML output
     * @throws RuntimeException when source is missing
     */
    public function render(string $name, array $data = []): string
    {
        $sourcePath   = $this->loader->getSourcePath($name);
        if (!is_file($sourcePath)) {
            throw new RuntimeException("Template source not found: {$sourcePath}");
        }

        // Load raw source and extract sections
        $raw = file_get_contents($sourcePath);
        [$raw, $sections] = $this->extractSections($raw);

        // If child extends a parent, merge
        if (isset($sections['__extends'])) {
            $parentName   = $sections['__extends'];
            $parentPath   = $this->loader->getSourcePath($parentName);
            if (!is_file($parentPath)) {
                throw new RuntimeException("Parent template not found: {$parentPath}");
            }
            $parentRaw  = file_get_contents($parentPath);
            $raw = $this->replaceYields($parentRaw, $sections);
        }

        // Compile and render, using cache if enabled
        $compiledPath = $this->getCompiledPath($name);

        if ($this->cacheEnabled) {
            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0755, true);
            }

            if (
                !is_file($compiledPath)
                || filemtime($sourcePath) > filemtime($compiledPath)
            ) {
                $ast = $this->parser->parse($raw);
                $php = $this->compiler->compile($ast, $sourcePath);
                file_put_contents($compiledPath, $php);
            }

            extract($data, EXTR_SKIP);
            ob_start();
            try {
                include $compiledPath;

                return ob_get_clean();
            } catch (Throwable $e) {
                ob_end_clean();

                // Enhanced error message with variable context
                $errorMsg = "Error including compiled template: " . $e->getMessage();
                if (str_contains($e->getMessage(), 'htmlspecialchars')) {
                    $nullVars = array_keys(array_filter($data, fn($v) => $v === null));
                    if (!empty($nullVars)) {
                        $errorMsg .= " (Null variables found: " . implode(', ', $nullVars) . ")";
                    }
                }

                throw new RuntimeException($errorMsg, 0, $e);
            }
        }

        // No cache: compile and eval on the fly
        $ast = $this->parser->parse($raw);
        $php = $this->compiler->compile($ast, $sourcePath);

        // Strip PHP tags for eval
        if (str_starts_with($php, '<?php')) {
            $php = substr($php, 5);
        }
        if (str_ends_with($php, '?>')) {
            $php = substr($php, 0, -2);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        eval($php);
        return ob_get_clean();
    }

    /**
     * Clear cached compiled templates.
     */
    public function clearCache(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }
        foreach (glob($this->cacheDir . '/*.php') as $file) {
            @unlink($file);
        }
    }

    /**
     * Convert template name to compiled file path.
     */
    private function getCompiledPath(string $name): string
    {
        $file = str_replace(['.', '/'], '_', $name) . '.php';
        return $this->cacheDir . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * Extract @extends and all @section blocks.
     * Returns [modifiedSource, sectionsMap]
     *
     * @param string $source
     * @return array{0:string,1:array<string,string>}
     */
    private function extractSections(string $source): array
    {
        $sections = [];

        // Match @extends('base') or @extends("base")
        if (preg_match(
            '/@extends\((?:\'|")(?<view>.+?)(?:\'|")\)/',
            $source,
            $m
        )) {
            $sections['__extends'] = $m['view'];
            // Remove only the first occurrence
            $source = preg_replace(
                '/@extends\((?:\'|").+?(?:\'|")\)/',
                '',
                $source,
                1
            );
        }

        // Match all @section('name') ... @endsection blocks
        $sectionPattern = '/@section\((?:\'|")(?<name>.+?)(?:\'|")\)(?<content>.*?)@endsection/s';
        $source = preg_replace_callback(
            $sectionPattern,
            function (array $m) use (&$sections) {
                $sections[$m['name']] = $m['content'];
                return '';
            },
            $source
        );

        return [$source, $sections];
    }

    /**
     * Replace @yield('name') in the parent source with the corresponding section content.
     *
     * @param string               $source
     * @param array<string,string> $sections
     * @return string
     */
    private function replaceYields(string $source, array $sections): string
    {
        $pattern = '/@yield\((?:\'|")(?<section>.+?)(?:\'|")\)/';
        return preg_replace_callback(
            $pattern,
            function (array $m) use ($sections) {
                return $sections[$m['section']] ?? '';
            },
            $source
        );
    }
}
