<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use MonkeysLegion\Template\Cache\FilesystemViewCache;
use MonkeysLegion\Template\Cache\Psr16ViewCache;
use Psr\SimpleCache\CacheInterface as PsrCacheInterface;
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
    /** @var array<string, mixed> Globally shared data */
    private array $shared = [];

    /** @var array<string, list<callable>> View composers by pattern */
    private array $composers = [];

    /** @var list<callable> Event listeners for rendering/rendered events */
    private array $renderingListeners = [];

    /** @var list<callable> Event listeners for rendered events */
    private array $renderedListeners = [];

    /**
     * @param Loader               $loader    Locates template files by name
     * @param Compiler             $compiler  Converts template source into PHP code
     * @param Renderer             $renderer  Executes compiled PHP and captures output
     * @param string               $cacheDir  Directory where compiled templates are stored
     * @param array<string, mixed> $config    Configuration options:
     *   - strict_mode: bool — enable strict escaping
     *   - production: bool — skip filemtime checks (requires view:compile on deploy)
     *   - cache: PsrCacheInterface — PSR-16 store for compiled template + fragment caching
     */
    public function __construct(
        private Loader $loader,
        private Compiler $compiler,
        private Renderer $renderer,
        private string $cacheDir,
        array $config = [],
    ) {
        if (!empty($config['strict_mode'])) {
            $this->compiler->setStrictMode(true);
        }

        // Production mode: skip filemtime checks
        if (!empty($config['production'])) {
            $this->renderer->setViewCache(new FilesystemViewCache($cacheDir, checkMtime: false));
        }

        // PSR-16 cache integration
        if (isset($config['cache']) && $config['cache'] instanceof PsrCacheInterface) {
            $this->renderer->setViewCache(new Psr16ViewCache($config['cache'], $cacheDir));
            $this->renderer->setFragmentCache($config['cache']);
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
        // Apply shared data
        $mergedData = array_merge($this->shared, $data);

        // Run composers
        $viewData = new ViewData($name, $mergedData);
        $this->runComposers($name, $viewData);

        // Dispatch rendering event
        $event = new Events\ViewRendering($name, $viewData->getData());
        foreach ($this->renderingListeners as $listener) {
            $listener($event);
        }

        // Execute and return HTML
        $output = $this->renderer->render($name, $event->data);

        // Dispatch rendered event
        $renderedEvent = new Events\ViewRendered($name, $event->data, $output);
        foreach ($this->renderedListeners as $listener) {
            $listener($renderedEvent);
        }

        return $renderedEvent->output;
    }

    /**
     * Create a deferred ViewData object for later rendering.
     *
     * @param array<string, mixed> $data
     */
    public function make(string $name, array $data = []): ViewData
    {
        $mergedData = array_merge($this->shared, $data);
        return new ViewData($name, $mergedData, $this->renderer);
    }

    /**
     * Share data globally across all views.
     */
    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * Get all shared data.
     *
     * @return array<string, mixed>
     */
    public function getShared(): array
    {
        return $this->shared;
    }

    /**
     * Register a view composer for one or more view patterns.
     *
     * @param string|list<string> $views
     * @param callable $composer
     */
    public function composer(string|array $views, callable $composer): void
    {
        $views = is_array($views) ? $views : [$views];
        foreach ($views as $view) {
            $this->composers[$view] ??= [];
            $this->composers[$view][] = $composer;
        }
    }

    /**
     * Register a listener for the rendering event (before render).
     */
    public function rendering(callable $listener): void
    {
        $this->renderingListeners[] = $listener;
    }

    /**
     * Register a listener for the rendered event (after render).
     */
    public function rendered(callable $listener): void
    {
        $this->renderedListeners[] = $listener;
    }

    /**
     * Compile and render a template string (no file needed).
     *
     * @param array<string, mixed> $data
     */
    public function renderString(string $template, array $data = []): string
    {
        $mergedData = array_merge($this->shared, $data);
        return $this->renderer->renderString($template, $mergedData);
    }

    /**
     * Create a TestView for the given template (testing entry point).
     *
     * @param array<string, mixed> $data
     */
    public function test(string $name, array $data = []): Testing\TestView
    {
        $output = $this->render($name, $data);
        return Testing\TestView::fromRendered($name, $output, $data);
    }

    /**
     * Render a template as a stream of chunks (generator).
     *
     * @param array<string, mixed> $data
     * @return \Generator<int, string, mixed, void>
     */
    public function stream(string $name, array $data = []): \Generator
    {
        $mergedData = array_merge($this->shared, $data);
        $streamRenderer = new Support\StreamRenderer(
            new Parser(),
            $this->compiler,
            $this->loader,
            $this->cacheDir,
        );

        yield from $streamRenderer->renderStream($name, $mergedData);
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

    /**
     * Register a function component (closure-based, lightweight).
     *
     * Usage:
     *   $view->component('badge', fn(string $text, string $color = 'blue') =>
     *       "<span class=\"badge bg-{$color}\">" . e($text) . "</span>"
     *   );
     *
     * Then in templates: <x-badge text="Active" color="green" />
     */
    public function component(string $name, callable $fn): void
    {
        $this->renderer->registerFunctionComponent($name, $fn);
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
     * 
     * @param string $themeName The name of the theme folder (e.g. 'dark')
     * @param string|null $baseThemesPath The base path where themes are stored.
     */
    public function setTheme(string $themeName, ?string $baseThemesPath = null): void
    {
        $baseThemesPath = $baseThemesPath ?? (function_exists('resource_path') ? resource_path('themes') : 'resources/themes');
        
        $themePath = rtrim($baseThemesPath, '/\\') . DIRECTORY_SEPARATOR . $themeName;
        
        $this->loader->prependPath($themePath);

        // Clear L1 pool — source paths may have changed
        $this->renderer->getTemplatePool()->clear();
    }

    /**
     * Run composers matching the given view name.
     */
    private function runComposers(string $name, ViewData $viewData): void
    {
        foreach ($this->composers as $pattern => $composers) {
            if ($this->viewMatchesPattern($name, $pattern)) {
                foreach ($composers as $composer) {
                    $composer($viewData);
                }
            }
        }
    }

    /**
     * Check if a view name matches a composer pattern.
     *
     * Supports wildcard `*` matching (e.g., 'layouts.*' matches 'layouts.app').
     */
    private function viewMatchesPattern(string $name, string $pattern): bool
    {
        if ($name === $pattern) {
            return true;
        }

        // Convert wildcard pattern to regex
        $regex = '/^' . str_replace(['.', '*'], ['\.', '.*'], $pattern) . '$/';
        return (bool) preg_match($regex, $name);
    }
}

