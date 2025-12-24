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
    private \MonkeysLegion\Template\Contracts\ParserInterface $parser;
    private \MonkeysLegion\Template\Contracts\CompilerInterface $compiler;
    private \MonkeysLegion\Template\Contracts\LoaderInterface $loader;
    private bool $cacheEnabled;
    private string $cacheDir;
    /** @var array<string, array<string>> Stacks for @push */
    private array $pushStacks = [];

    /** @var array<string, array<string>> Stacks for @prepend */
    private array $prependStacks = [];

    /** @var array<string, bool> Tracked hashes for @once */
    private array $onceHashes = [];

    /** @var array<string, string> Placeholder mapping for stacks */
    private array $stackPlaceholders = [];

    /** @var array<int, \MonkeysLegion\Template\Support\Loop> Stack of loop variables */
    private array $loopStack = [];
    public function __construct(
        \MonkeysLegion\Template\Contracts\ParserInterface $parser,
        \MonkeysLegion\Template\Contracts\CompilerInterface $compiler,
        \MonkeysLegion\Template\Contracts\LoaderInterface $loader,
        bool $cacheEnabled = true,
        string $cacheDir = ''
    ) {
        $this->parser       = $parser;
        $this->compiler     = $compiler;
        $this->loader       = $loader;
        $this->cacheEnabled = $cacheEnabled;
        $this->cacheDir     = $cacheDir !== ''
            ? rtrim($cacheDir, DIRECTORY_SEPARATOR)
            : (function_exists('base_path') ?
                /** @phpstan-ignore argument.type */
                call_user_func('base_path', 'var/cache/views') : sys_get_temp_dir() . '/monkeyslegion/views');
    }

    /**
     * Render a view by name
     *
     * @param string $__name View name (dot notation allowed)
     * @param array<string, mixed> $__data Data to pass to the view
     * @return string Rendered HTML output
     * @throws RuntimeException when source is missing
     */
    public function render(string $__name, array $__data = []): string
    {
        try {
            // Initialize the variable scope system with global data
            $scope = new VariableScope($__data);
            VariableScope::setCurrent($scope);

            $__sourcePath = $this->loader->getSourcePath($__name);
            if (!is_file($__sourcePath)) {
                throw new RuntimeException("Template source not found: {$__sourcePath}");
            }

            $__raw = file_get_contents($__sourcePath);
            if ($__raw === false) {
                 throw new RuntimeException("Failed to read template source: {$__sourcePath}");
            }

            // Layout handling: extract sections from child and inject into parent
            [$__raw, $sections] = $this->extractSections($__raw);

            if (isset($sections['__extends'])) {
                $parentName = $sections['__extends'];

                $parentPath = $this->loader->getSourcePath($parentName);
                if (!is_file($parentPath)) {
                    throw new RuntimeException("Parent template not found: {$parentPath}");
                }

                $parentRaw = file_get_contents($parentPath);
                if ($parentRaw === false) {
                    throw new RuntimeException("Failed to read parent template: {$parentPath}");
                }

                $__raw = $this->replaceYields($parentRaw, $sections);
            }

            // Compile and render, using cache if enabled
            $__compiledPath = $this->getCompiledPath($__name);

            if ($this->cacheEnabled) {
                if (!is_dir($this->cacheDir)) {
                    mkdir($this->cacheDir, 0755, true);
                }

                if (
                    !is_file($__compiledPath)
                    || filemtime($__sourcePath) > filemtime($__compiledPath)
                ) {
                    // IMPORTANT: pass RAW source to Compiler.
                    // Compiler::compile() will call Parser internally.
                    $php = $this->compiler->compile($__raw, $__sourcePath);
                    file_put_contents($__compiledPath, $php);
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
                    include $__compiledPath;

                    // Get the buffered output from the included template
                    $__templateOutput = ob_get_clean();

                    if ($__templateOutput === false) {
                        throw new RuntimeException(sprintf(
                            'Renderer buffer was closed while rendering view [%s]. ' .
                            'Check for ob_end_clean()/ob_clean() in your views or components.',
                            $__name
                        ));
                    }

                    return $this->replaceStackPlaceholders($__templateOutput);
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
            $php = $this->compiler->compile($__raw, $__sourcePath);

            // Ensure cache directory exists (we still need a temp file to include)
            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0755, true);
            }

            // Reuse the same compiled path even when cache is disabled
            $tmpCompiledPath = $__compiledPath;
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
                $__templateOutput = ob_get_clean();

                if ($__templateOutput === false) {
                    throw new RuntimeException(sprintf(
                        'Renderer buffer was closed while rendering view [%s] (no-cache). ' .
                        'Check for ob_end_clean()/ob_clean() in your views or components.',
                        $__name
                    ));
                }

                return $__templateOutput;
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
     * Resolve a component name to a file path using the loader.
     * Tries prefixes: components, layouts, partials.
     *
     * @param string $name Component name (e.g. 'ui.button')
     * @return string Absolute file path
     * @throws RuntimeException if not found
     */
    public function resolveComponent(string $name): string
    {
        // Normalize name to dot notation just in case
        $name = str_replace('/', '.', $name);

        $prefixes = ['components', 'layouts', 'partials'];

        foreach ($prefixes as $prefix) {
            try {
                // Try to find: prefix.name
                // e.g. components.ui.button
                return $this->loader->getSourcePath($prefix . '.' . $name);
            } catch (RuntimeException) {
                // Not found in this prefix, continue
            }
        }

        throw new RuntimeException("Component not found: <x-{$name}> (searched in components, layouts, partials)");
    }

    /**
     * Render a component file directly.
     * Use file-based caching and inclusion to avoid eval().
     *
     * @param string $__path Component file path
     * @param array<string, mixed> $__data Component data (attributes and slots)
     * @return string Rendered component
     */
    public function renderComponent(string $__path, array $__data = []): string
    {
        // We assume $__data contains 'attributes' (AttributeBag) and 'slots' (SlotCollection)
        // plus any other raw attributes passed from the parser.

        // We need to form the final data array for the component.
        // The parser passes us ALL attributes as an array in $__data['attributes'] usually,
        // or effectively merged into $__data.
        // Let's assume $__data is the raw array of attributes + slots.

        $level = ob_get_level();
        ob_start();

        try {
            if (!is_file($__path)) {
                throw new RuntimeException("Component file not found: {$__path}");
            }

            // 2. Read source to extract props
            // (In a production environment, we might cache the "extracted props" info too,
            // but for now we read source to get the @props definition)
            $__source = file_get_contents($__path);
            if ($__source === false) {
                 throw new RuntimeException("Failed to read component source: {$__path}");
            }

            // Extract @props/@param
            $props = $this->parser->extractComponentParams($__source);

            // 3. Create Isolated Scope
            // The Attributes passed in $__data need to be separated from Props.
            // attributes = $__data - props

            // However, VariableScope::createIsolatedScope expects (attributes, definedProps).
            // We need to separate what was passed in ($__data) vs key/values.

            $scope = VariableScope::getCurrent();

            // $__data usually comes from $__component_attrs which is [key => value]
            // We should ensure we look for __slots/slots in $__data.
            $slots = $__data['slots'] ?? new \MonkeysLegion\Template\Support\SlotCollection([]);
            $passedAttrs = $__data;
            unset($passedAttrs['slots']); // Remove slots from attributes

            $scope->createIsolatedScope($passedAttrs, $props);

            // 4. Compile if needed
            $__compiledPath = $this->getCompiledPathForComponent($__path);

            if ($this->cacheEnabled) {
                if (!is_dir($this->cacheDir)) {
                    mkdir($this->cacheDir, 0755, true);
                }

                if (!is_file($__compiledPath) || filemtime($__path) > filemtime($__compiledPath)) {
                    // Remove @props directives from source before compiling
                    // (Strictly speaking, the Compiler or Parser might handle this, but
                    // removePropsDirectives is in Parser and called manually in the old "eval" code)
                    // The old code called: $__parser->removePropsDirectives($__component_source);
                    // BEFORE compilation.

                    // We can reuse that logic:
                    // But wait, we shouldn't modify source on every request if cached.
                    // We only do this when compiling.
                    $cleanSource = $this->parser->removePropsDirectives($__source);
                    $php = $this->compiler->compile($cleanSource, $__path);
                    file_put_contents($__compiledPath, $php);
                }
            } else {
                // No cache mode
                if (!is_dir($this->cacheDir)) {
                    mkdir($this->cacheDir, 0755, true);
                }
                $cleanSource = $this->parser->removePropsDirectives($__source);
                $php = $this->compiler->compile($cleanSource, $__path);
                file_put_contents($__compiledPath, $php);
                // We might want to unlink later, or just overwrite next time.
                // For components, unique paths per request is tricky if we don't cache.
                // But getCompiledPathForComponent uses md5 of path, so it's stable per file.
            }

            // 5. Extract Scope and Include
            // The Scope now holds the "bag" of variables:
            // - props as variables
            // - $attributes as AttributeBag
            // - $slots as SlotCollection (we need to inject this)

            $scopedData = $scope->getCurrentScope();

            // Inject slots if not already in scope (Scope usually doesn't manage slots explicitly unless passed)
            // In the old code:
            // $slots = SlotCollection::fromArray(...);
            // $slot = $slots->getDefault();
            // extract($__isolated_data);

            // So we need to put $slots and $slot into the extraction array.
            $scopedData['slots'] = $slots;
            $scopedData['slot']  = $slots->getDefault();

            extract($scopedData, EXTR_SKIP);

            include $__compiledPath;

            $output = ob_get_clean();

            if ($output === false) {
                 throw new RuntimeException("Component buffer closed unexpectedly: {$__path}");
            }

            return $output;
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw new RuntimeException("Error rendering component {$__path}: " . $e->getMessage(), 0, $e);
        } finally {
            // 6. Pop Scope
            if (isset($scope)) {
                $scope->popScope();
            }
        }
    }

    // Helper to get consistent compiled path for components
    private function getCompiledPathForComponent(string $path): string
    {
        // Use a hash of the full path to avoid collisions
        return $this->cacheDir . DIRECTORY_SEPARATOR . 'cmp_' . md5($path) . '.php';
    }

    /**
     * Clear cached compiled templates.
     */
    public function clearCache(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }
        $files = glob($this->cacheDir . '/*.php');
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
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
        if (
            preg_match(
                '/@extends\((?:\'|")(?<view>.+?)(?:\'|")\)/',
                $source,
                $m
            )
        ) {
            $sections['__extends'] = $m['view'];
            // Remove only the first occurrence
            $source = (string)preg_replace(
                '/@extends\((?:\'|").+?(?:\'|")\)/',
                '',
                $source,
                1
            );
        }

        // Match all @section('name') ... @endsection blocks
        $sectionPattern = '/@section\((?:\'|")(?<name>.+?)(?:\'|")\)(?<content>.*?)@endsection/s';
        $source = (string)preg_replace_callback(
            $sectionPattern,
            function (array $m) use (&$sections) {
                $sections[$m['name']] = $m['content'];
                return '';
            },
            $source
        );
        return [(string)$source, $sections];
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
        return (string)preg_replace_callback(
            $pattern,
            function (array $m) use ($sections) {
                $sectionName = $m['section'];
                return $sections[$sectionName] ?? '';
            },
            $source
        );
    }
    /**
     * Start pushing content to a named stack.
     */
    public function startPush(string $section): void
    {
        ob_start();
        // We use a marker in the specific stack to know where to put content when stopping
        // Note: nesting works if we always push to the end of the array.
        // When stopping, we look for the last marker.
        $this->pushStacks[$section][] = '__PUSH_START__';
    }

    /**
     * Stop pushing content.
     */
    public function stopPush(): void
    {
        $content = ob_get_clean();
        if ($content === false) {
            return;
        }

        // Find the most recent stack we started in ANY stack group?
        // No, we need to know WHICH stack we were pushing to.
        // But the directives startPush('name') ... stopPush() don't pass name to stop.
        // So we must search all stacks?
        // Or better: keep a separate stack of "active stack names".

        // Let's implement the search approach as it's what I wrote before:
        foreach ($this->pushStacks as $name => &$stack) {
            if (!empty($stack) && end($stack) === '__PUSH_START__') {
                array_pop($stack);
                $stack[] = $content;
                return;
            }
        }
    }

    /**
     * Start prepending content to a named stack.
     */
    public function startPrepend(string $section): void
    {
        ob_start();
        $this->prependStacks[$section][] = '__PREPEND_START__';
    }

    /**
     * Stop prepending content.
     */
    public function stopPrepend(): void
    {
        $content = ob_get_clean();
        if ($content === false) {
            return;
        }

        foreach ($this->prependStacks as $name => &$stack) {
            if (!empty($stack) && end($stack) === '__PREPEND_START__') {
                array_pop($stack);
                $stack[] = $content;
                return;
            }
        }
    }

    /**
     * Yield the content of a stack (returns placeholder).
     */
    public function yieldPush(string $section, string $default = ''): string
    {
        $placeholder = "<!-- __ML_STACK_{$section}__ -->";
        $this->stackPlaceholders[$placeholder] = $section;
        // Store default in case stack is empty?
        // We can check emptiness later.
        return $placeholder;
    }

    /**
     * Replace stack placeholders with actual content.
     */
    private function replaceStackPlaceholders(string $content): string
    {
        foreach ($this->stackPlaceholders as $placeholder => $section) {
            $prepends = $this->prependStacks[$section] ?? [];
            $pushes = $this->pushStacks[$section] ?? [];

            $output = '';

            // Prepends (reverse order)
            foreach (array_reverse($prepends) as $item) {
                $output .= $item;
            }

            // Pushes (normal order)
            foreach ($pushes as $item) {
                $output .= $item;
            }

            $content = str_replace($placeholder, $output, $content);
        }

        return $content;
    }

    /**
     * Add a hash to the once tracker.
     * Returns true if newly added, false if already exists.
     */
    public function addOnceHash(string $hash): bool
    {
        if (isset($this->onceHashes[$hash])) {
            return false;
        }
        $this->onceHashes[$hash] = true;
        return true;
    }

    /**
     * Add a new loop to the stack.
     *
     * @param mixed $data Iterable data
     */
    public function addLoop(mixed $data): void
    {
        $parent = end($this->loopStack) ?: null;
        $this->loopStack[] = \MonkeysLegion\Template\Support\Loop::start($data, $parent);
    }

    /**
     * Pop the last loop from the stack.
     */
    public function popLoop(): void
    {
        array_pop($this->loopStack);
    }

    /**
     * Get the current loop variable.
     */
    public function getLastLoop(): ?\MonkeysLegion\Template\Support\Loop
    {
        return end($this->loopStack) ?: null;
    }
}
