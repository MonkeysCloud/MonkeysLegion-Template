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
        try {
            // Initialize the variable scope system with global data
            $scope = new VariableScope($data);
            VariableScope::setCurrent($scope);

            $sourcePath = $this->loader->getSourcePath($name);
            if (!is_file($sourcePath)) {
                throw new RuntimeException("Template source not found: {$sourcePath}");
            }

            $raw = file_get_contents($sourcePath);

            // Layout handling: extract sections from child and inject into parent
            [$raw, $sections] = $this->extractSections($raw);

            if (isset($sections['__extends'])) {
                $parentName = $sections['__extends'];

                $parentPath = $this->loader->getSourcePath($parentName);
                if (!is_file($parentPath)) {
                    throw new RuntimeException("Parent template not found: {$parentPath}");
                }

                $parentRaw = file_get_contents($parentPath);

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
                    // IMPORTANT: pass RAW source to Compiler.
                    // Compiler::compile() will call Parser internally.
                    $php = $this->compiler->compile($raw, $sourcePath);
                    file_put_contents($compiledPath, $php);
                }

                // ==== Buffered include with level tracking (cache enabled) ====
                $level = ob_get_level();
                ob_start();

                try {
                    // Make data available globally for slots
                    $GLOBALS['__data']     = $scope->getCurrentScope();
                    $GLOBALS['__ml_attrs'] = [];

                    // Extract data for template
                    extract($scope->getCurrentScope(), EXTR_SKIP);

                    // Ensure $slots is always defined (for layouts using @if($slots->has(...)))
                    if (!isset($slots)) {
                        $slots = \MonkeysLegion\Template\Support\SlotCollection::fromArray([]);
                    }

                    // Include compiled template inside this output buffer
                    include $compiledPath;

                    // Get the buffered output from the included template
                    $templateOutput = ob_get_clean();

                    if ($templateOutput === false) {
                        throw new RuntimeException(sprintf(
                            'Renderer buffer was closed while rendering view [%s]. ' .
                            'Check for ob_end_clean()/ob_clean() in your views or components.',
                            $name
                        ));
                    }

                    return $templateOutput;
                } catch (Throwable $e) {
                    // Clean only buffers we started
                    while (ob_get_level() > $level) {
                        ob_end_clean();
                    }

                    // Enhanced error message with variable context
                    $errorMsg = "Error rendering template: " . $e->getMessage();
                    if (str_contains($e->getMessage(), 'headers already sent')) {
                        $errorMsg .= " (Check for whitespace or output before PHP tags in components)";
                    }

                    throw new RuntimeException($errorMsg, 0, $e);
                } finally {
                    unset($GLOBALS['__ml_attrs'], $GLOBALS['__data']);
                }
            }

            // ==========================
            // No cache: compile and include on the fly (NO eval)
            // ==========================
            // IMPORTANT: pass RAW source to Compiler.
            // Compiler::compile() will call Parser internally.
            $php = $this->compiler->compile($raw, $sourcePath);

            // Ensure cache directory exists (we still need a temp file to include)
            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0755, true);
            }

            // Reuse the same compiled path even when cache is disabled
            $tmpCompiledPath = $compiledPath;
            file_put_contents($tmpCompiledPath, $php);

            // ==== Buffered include with level tracking (no cache) ====
            $level = ob_get_level();
            ob_start();

            try {
                // Make data available globally for slots
                $GLOBALS['__data']     = $scope->getCurrentScope();
                $GLOBALS['__ml_attrs'] = [];

                // Extract data for the template
                extract($scope->getCurrentScope(), EXTR_SKIP);

                // Ensure $slots is always defined (for layouts using slot-based regions)
                if (!isset($slots)) {
                    $slots = \MonkeysLegion\Template\Support\SlotCollection::fromArray([]);
                }

                // Include compiled template inside the current output buffer
                include $tmpCompiledPath;

                // Get the buffered output
                $templateOutput = ob_get_clean();

                if ($templateOutput === false) {
                    throw new RuntimeException(sprintf(
                        'Renderer buffer was closed while rendering view [%s] (no-cache). ' .
                        'Check for ob_end_clean()/ob_clean() in your views or components.',
                        $name
                    ));
                }

                return $templateOutput;
            } catch (Throwable $e) {
                // Clean only buffers we started
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }

                throw new RuntimeException(
                    "Error rendering template (no-cache): "
                    . $e->getMessage()
                    . " in " . $e->getFile()
                    . ":" . $e->getLine(),
                    0,
                    $e
                );
            } finally {
                unset($GLOBALS['__ml_attrs'], $GLOBALS['__data']);
                // Since caching is disabled, we can optionally remove the temp compiled file
                @unlink($tmpCompiledPath);
            }
        } catch (Throwable $e) {
            // Do NOT nuke all buffers globally; just make sure we don't leak ours.
            // (If you really want to, you can restore to a known level here,
            //  but at this point we've already cleaned in the inner blocks.)

            unset($GLOBALS['__ml_attrs'], $GLOBALS['__data']);

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
        // Track current buffer level so we only clean what we started
        $level = ob_get_level();
        ob_start();

        try {
            $source = file_get_contents($path);
            // PASS RAW SOURCE to Compiler; it will call Parser internally
            $compiled = $this->compiler->compile($source, $path);

            // Ensure cache dir exists for temp component file
            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0755, true);
            }

            $tmpPath = $this->cacheDir . DIRECTORY_SEPARATOR . 'cmp_' . md5($path . microtime(true)) . '.php';
            file_put_contents($tmpPath, $compiled);

            // Make component data available
            extract($data, EXTR_SKIP);

            // Execute compiled component template
            include $tmpPath;

            // Capture output
            $output = ob_get_clean();

            if ($output === false) {
                throw new RuntimeException(
                    "Component buffer was closed while rendering {$path}. " .
                    "Check for ob_end_clean()/ob_clean() calls inside the component."
                );
            }

            return $output;
        } catch (\Throwable $e) {
            // Restore buffers only down to the level we started
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw new RuntimeException(
                "Error rendering component {$path}: " . $e->getMessage(),
                0,
                $e
            );
        } finally {
            if (isset($tmpPath) && is_file($tmpPath)) {
                @unlink($tmpPath);
            }
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
