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
        // Start output buffering immediately to prevent any headers already sent errors
        ob_start();

        try {
            // Initialize the variable scope system with global data
            $scope = new VariableScope($data);
            VariableScope::setCurrent($scope);

            $sourcePath = $this->loader->getSourcePath($name);
            if (!is_file($sourcePath)) {
                ob_end_clean(); // Clean the buffer before throwing
                throw new RuntimeException("Template source not found: {$sourcePath}");
            }

            // Log step 1: Loading raw source
            $raw = file_get_contents($sourcePath);

            // Log step 2: Extracting sections
            [$raw, $sections] = $this->extractSections($raw);

            // Log inheritance information
            if (isset($sections['__extends'])) {
                $parentName = $sections['__extends'];

                $parentPath = $this->loader->getSourcePath($parentName);
                if (!is_file($parentPath)) {
                    throw new RuntimeException("Parent template not found: {$parentPath}");
                }

                $parentRaw = file_get_contents($parentPath);

                $raw = $this->replaceYields($parentRaw, $sections);
            } else {
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

                try {
                    // Make data available globally for slots
                    $GLOBALS['__data'] = $scope->getCurrentScope();

                    // Extract data for template
                    extract($scope->getCurrentScope(), EXTR_SKIP);

                    // Include compiled template inside this output buffer
                    include $compiledPath;

                    // Get the buffered output from the included template
                    $templateOutput = ob_get_clean();

                    // Clean up globals
                    unset($GLOBALS['__ml_attrs']);
                    unset($GLOBALS['__data']);

                    return $templateOutput;
                } catch (Throwable $e) {
                    // Clean up buffer and globals on error
                    ob_end_clean();
                    unset($GLOBALS['__ml_attrs']);
                    unset($GLOBALS['__data']);

                    // Enhanced error message with variable context
                    $errorMsg = "Error rendering template: " . $e->getMessage();
                    if (str_contains($e->getMessage(), 'headers already sent')) {
                        $errorMsg .= " (Check for whitespace or output before PHP tags in components)";
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

            // Use a nested output buffer for eval code
            ob_start();

            // Make data available globally for slots
            $GLOBALS['__data'] = $scope->getCurrentScope();

            // Set an empty array for component attributes
            $GLOBALS['__ml_attrs'] = [];

            // Extract data from the root scope only
            extract($scope->getCurrentScope(), EXTR_SKIP);

            // Evaluate template code
            eval($php);

            // Get the buffered output from the evaluated code
            $evalOutput = ob_get_clean();

            // Clean up global
            unset($GLOBALS['__ml_attrs']);
            unset($GLOBALS['__data']);

            // Clear the main buffer and return the eval output
            ob_end_clean();
            return $evalOutput;
        } catch (Throwable $e) {
            // Make sure to clean all buffers
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Clean up globals if exception occurs
            unset($GLOBALS['__ml_attrs']);
            unset($GLOBALS['__data']);

            throw $e;
        }
    }

    /**
     * Render a component file directly.
     * This is called when a component is included during rendering.
     *
     * @param string $path Component file path
     * @param array $data Component data and slots
     * @return string Rendered component
     */
    public function renderComponent(string $path, array $data = []): string
    {
        // Start output buffering
        ob_start();

        try {
            // Process the component file through the full template pipeline
            $source = file_get_contents($path);
            $processed = $this->parser->parse($source);
            $compiled = $this->compiler->compile($processed, $path);

            // Execute the compiled code with the provided data
            extract($data, EXTR_SKIP);
            eval('?>' . substr($compiled, strpos($compiled, '?>') + 2));

            return ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw new RuntimeException("Error rendering component {$path}: " . $e->getMessage(), 0, $e);
        }
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
                $sectionName = $m['section'];
                return $sections[$sectionName] ?? '';
            },
            $source
        );
    }
}
