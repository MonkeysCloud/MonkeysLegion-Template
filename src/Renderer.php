<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use MonkeysLegion\Template\Cache\CompiledTemplatePool;
use MonkeysLegion\Template\Cache\FilesystemViewCache;
use MonkeysLegion\Template\Cache\ViewCacheInterface;
use MonkeysLegion\Template\Exceptions\ViewException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface as PsrCacheInterface;
use RuntimeException;
use Throwable;

final class Renderer
{
    private \MonkeysLegion\Template\Contracts\ParserInterface $parser;
    private \MonkeysLegion\Template\Contracts\CompilerInterface $compiler;
    private \MonkeysLegion\Template\Contracts\LoaderInterface $loader;
    private bool $cacheEnabled;
    private string $cacheDir;
    /** @var array<string, list<string>> */
    private array $pushStacks = [];

    /** @var array<string, list<string>> */
    private array $prependStacks = [];

    /** @var array<string, true> */
    private array $onceHashes = [];

    /** @var array<string, string> */
    private array $stackPlaceholders = [];

    /** @var list<\MonkeysLegion\Template\Support\Loop> */
    private array $loopStack = [];

    /** @var ServerRequestInterface|null PSR-7 request for HTMX/fragment support */
    private ?ServerRequestInterface $request = null;

    /** @var array<string, callable> Registered function components */
    private array $functionComponents = [];

    /** @var list<callable> Rendering event listeners (pre-render) */
    private array $renderingListeners = [];

    /** @var list<callable> Rendered event listeners (post-render) */
    private array $renderedListeners = [];

    /** View cache adapter (filesystem or PSR-16 backed) */
    private ViewCacheInterface $viewCache;

    /** In-memory per-request template pool (L1 cache) */
    private CompiledTemplatePool $templatePool;

    /** Optional PSR-16 cache for @cache directive fragment caching */
    private ?PsrCacheInterface $fragmentCache = null;

    public function __construct(
        \MonkeysLegion\Template\Contracts\ParserInterface $parser,
        \MonkeysLegion\Template\Contracts\CompilerInterface $compiler,
        \MonkeysLegion\Template\Contracts\LoaderInterface $loader,
        bool $cacheEnabled = true,
        string $cacheDir = '',
        private ?\MonkeysLegion\Template\Support\DirectiveRegistry $registry = null,
        ?ViewCacheInterface $viewCache = null,
    ) {
        $this->parser       = $parser;
        $this->compiler     = $compiler;
        $this->loader       = $loader;
        $this->cacheEnabled = $cacheEnabled;
        $this->registry     = $registry ?? new \MonkeysLegion\Template\Support\DirectiveRegistry();
        $this->cacheDir     = $cacheDir !== ''
            ? rtrim($cacheDir, DIRECTORY_SEPARATOR)
            : (function_exists('base_path')
                ? (string) call_user_func('base_path', 'var/cache/views') /** @phpstan-ignore argument.type */
                : sys_get_temp_dir() . '/monkeyslegion/views');

        $this->viewCache = $viewCache ?? new FilesystemViewCache($this->cacheDir);
        $this->templatePool = new CompiledTemplatePool();
    }

    /**
     * Set the view cache adapter.
     */
    public function setViewCache(ViewCacheInterface $cache): void
    {
        $this->viewCache = $cache;
    }

    /**
     * Get the current view cache adapter.
     */
    public function getViewCache(): ViewCacheInterface
    {
        return $this->viewCache;
    }

    /**
     * Set a PSR-16 cache for @cache directive fragment caching.
     */
    public function setFragmentCache(PsrCacheInterface $cache): void
    {
        $this->fragmentCache = $cache;
    }

    /**
     * Get the fragment cache (for compiled templates to use).
     */
    public function getFragmentCache(): ?PsrCacheInterface
    {
        return $this->fragmentCache;
    }

    /**
     * Get the in-memory template pool.
     */
    public function getTemplatePool(): CompiledTemplatePool
    {
        return $this->templatePool;
    }

    /**
     * @param array<string, mixed> $__data
     */
    public function render(string $__name, array $__data = []): string
    {
        try {
            // Dispatch rendering event (pre-render)
            $__renderingEvent = new Events\ViewRendering($__name, $__data);
            foreach ($this->renderingListeners as $__listener) {
                $__listener($__renderingEvent);
            }
            $__data = $__renderingEvent->data;

            $scope = new VariableScope($__data);
            VariableScope::setCurrent($scope);

            $__sourcePath = $this->loader->getSourcePath($__name);
            if (!is_file($__sourcePath)) {
                throw new RuntimeException("Template source not found: {$__sourcePath}");
            }

            // L1: Check in-memory pool first (avoids filemtime on repeated renders)
            if ($this->templatePool->has($__name)) {
                $poolMtime = $this->templatePool->getMtime($__name);
                $currentMtime = filemtime($__sourcePath);
                // Pool is valid if source hasn't changed since we cached it
                if ($currentMtime !== false && $poolMtime !== null && (float) $currentMtime <= $poolMtime) {
                    $__compiledPath = $this->templatePool->getPath($__name);
                    return $this->executeCompiledTemplate($__name, $__compiledPath, $scope, $__data);
                }
                // Source changed — evict from pool and recompile
                $this->templatePool->forget($__name);
            }

            $__raw = file_get_contents($__sourcePath);
            if ($__raw === false) {
                throw new RuntimeException("Failed to read template source: {$__sourcePath}");
            }

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

            if ($this->cacheEnabled) {
                // L2: Check view cache adapter (filesystem/PSR-16)
                if ($this->viewCache->isFresh($__name, $__sourcePath)) {
                    $__compiledPath = $this->viewCache->getCompiledPath($__name, $__sourcePath);
                } else {
                    $php = $this->compiler->compile($__raw, $__sourcePath);
                    $__compiledPath = $this->viewCache->put($__name, $__sourcePath, $php);
                }

                // Store in L1 pool for same-request reuse
                $sourceMtime = filemtime($__sourcePath);
                $this->templatePool->put($__name, $__compiledPath, $sourceMtime !== false ? (float) $sourceMtime : 0.0);

                return $this->executeCompiledTemplate($__name, $__compiledPath, $scope, $__data);
            }

            // No cache: compile to temp file, execute, cleanup
            $php = $this->compiler->compile($__raw, $__sourcePath);
            $__compiledPath = $this->viewCache->put($__name, $__sourcePath, $php);

            try {
                return $this->executeCompiledTemplate($__name, $__compiledPath, $scope, $__data);
            } finally {
                @unlink($__compiledPath);
            }
        } catch (Throwable $e) {
            unset($GLOBALS['__ml_attrs'], $GLOBALS['__data']);
            throw $e;
        }
    }

    /**
     * Execute a compiled template file and return output.
     *
     * @param array<string, mixed> $__data
     */
    private function executeCompiledTemplate(
        string $__name,
        string $__compiledPath,
        VariableScope $scope,
        array $__data,
    ): string {
        $level = ob_get_level();
        ob_start();

        try {
            $GLOBALS['__data']     = $scope->getCurrentScope();
            $GLOBALS['__ml_attrs'] = [];
            extract($scope->getCurrentScope(), EXTR_SKIP);
            if (!isset($slots)) {
                $slots = \MonkeysLegion\Template\Support\SlotCollection::fromArray([]);
            }

            // Expose fragment cache to compiled templates
            $__ml_cache = $this->fragmentCache;

            include $__compiledPath;
            $__templateOutput = ob_get_clean();

            if ($__templateOutput === false) {
                throw new RuntimeException(sprintf(
                    'Renderer buffer was closed while rendering view [%s].',
                    $__name
                ));
            }

            return $this->dispatchRendered($__name, $__data, $this->replaceStackPlaceholders($__templateOutput));
        } catch (Throwable $e) {
            $this->handleViewException($e, $level);
        } finally {
            unset($GLOBALS['__ml_attrs'], $GLOBALS['__data']);
        }
    }
    public function resolveComponent(string $name): string
    {
        $name = str_replace('/', '.', $name);
        $prefixes = ['components', 'layouts', 'partials'];

        foreach ($prefixes as $prefix) {
            try {
                return $this->loader->getSourcePath($prefix . '.' . $name);
            } catch (RuntimeException) {}
        }

        throw new RuntimeException("Component not found: <x-{$name}>");
    }

    /**
     * @param array<string, mixed> $__data
     */
    public function renderComponent(string $__path, array $__data = []): string
    {
        $level = ob_get_level();
        ob_start();

        try {
            if (!is_file($__path)) {
                throw new RuntimeException("Component file not found: {$__path}");
            }

            $__source = file_get_contents($__path);
            if ($__source === false) {
                throw new RuntimeException("Failed to read component source: {$__path}");
            }

            $props = $this->parser->extractComponentParams($__source);
            $scope = VariableScope::getCurrent();
            $slots = $__data['slots'] ?? new \MonkeysLegion\Template\Support\SlotCollection([]);
            $passedAttrs = $__data;
            unset($passedAttrs['slots']);

            // Instantiate AttributeBag with passed attributes
            $attributeBag = new \MonkeysLegion\Template\Support\AttributeBag($passedAttrs);
            $passedAttrs['attributes'] = $attributeBag;

            $scope->createIsolatedScope($passedAttrs, $props);
            $__compiledPath = $this->getCompiledPathForComponent($__path);

            if ($this->cacheEnabled) {
                if (!is_dir($this->cacheDir)) {
                    mkdir($this->cacheDir, 0755, true);
                }
                if (!is_file($__compiledPath) || filemtime($__path) > filemtime($__compiledPath)) {
                    $cleanSource = $this->parser->removePropsDirectives($__source);
                    $php = $this->compiler->compile($cleanSource, $__path);
                    file_put_contents($__compiledPath, $php);
                }
            } else {
                if (!is_dir($this->cacheDir)) {
                    mkdir($this->cacheDir, 0755, true);
                }
                $cleanSource = $this->parser->removePropsDirectives($__source);
                $php = $this->compiler->compile($cleanSource, $__path);
                file_put_contents($__compiledPath, $php);
            }

            $scopedData = $scope->getCurrentScope();
            $scopedData['slots'] = $slots;
            $scopedData['slot']  = $slots->getDefault();
            // Ensure $attrs is available as an alias for $attributes
            if (isset($scopedData['attributes'])) {
                $scopedData['attrs'] = $scopedData['attributes'];
            }

            extract($scopedData, EXTR_SKIP);
            include $__compiledPath;

            $output = ob_get_clean();
            if ($output === false) {
                throw new RuntimeException("Component buffer closed unexpectedly: {$__path}");
            }
            return $output;
        } catch (Throwable $e) {
            $this->handleViewException($e, $level);
        } finally {
            if (isset($scope)) {
                $scope->popScope();
            }
        }
    }

    private function getCompiledPathForComponent(string $path): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . 'cmp_' . md5($path) . '.php';
    }

    /**
     * Compile and render a template string (no file required).
     *
     * Useful for email bodies, dynamic notifications, etc.
     *
     * @param array<string, mixed> $data
     */
    public function renderString(string $source, array $data = []): string
    {
        $scope = new VariableScope($data);
        VariableScope::setCurrent($scope);

        // Parse (handle components, slots, includes) then compile
        $parsed = $this->parser->parse($source);
        $php = $this->compiler->compile($parsed, 'string_template');

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $tmpPath = $this->cacheDir . DIRECTORY_SEPARATOR . 'str_' . md5($source) . '.php';
        file_put_contents($tmpPath, $php);

        $level = ob_get_level();
        ob_start();

        try {
            $GLOBALS['__data'] = $scope->getCurrentScope();
            $GLOBALS['__ml_attrs'] = [];
            extract($scope->getCurrentScope(), EXTR_SKIP);
            if (!isset($slots)) {
                $slots = \MonkeysLegion\Template\Support\SlotCollection::fromArray([]);
            }

            include $tmpPath;
            $output = ob_get_clean();

            if ($output === false) {
                throw new RuntimeException('Buffer closed during renderString.');
            }

            return $this->replaceStackPlaceholders($output);
        } catch (\Throwable $e) {
            $this->handleViewException($e, $level);
        } finally {
            unset($GLOBALS['__ml_attrs'], $GLOBALS['__data']);
            @unlink($tmpPath);
        }
    }

    public function clearCache(): void
    {
        // Clear L1 in-memory pool
        $this->templatePool->clear();

        // Flush the view cache adapter
        $this->viewCache->flush();

        // Also clear any remaining files directly in cacheDir
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
     * @return array{0: string, 1: array<string, string>}
     */
    private function extractSections(string $source): array
    {
        $sections = [];
        if (preg_match('/@extends\((?:\'|")(?<view>.+?)(?:\'|")\)/', $source, $m)) {
            $sections['__extends'] = $m['view'];
            $source = (string)preg_replace('/@extends\((?:\'|").+?(?:\'|")\)/', '', $source, 1);
        }

        // Handle shorthand @section('name', 'value') — no @endsection needed
        $shorthandPattern = '/@section\(\s*(?:\'|")(?<name>[^"\']+)(?:\'|")\s*,\s*(?<value>(?:[^()]+|\((?:[^()]+|\((?:[^()]+|\([^()]*\))*\))*\))*)\s*\)/';
        $source = (string)preg_replace_callback($shorthandPattern, function (array $m) use (&$sections) {
            $sections[$m['name']] = trim($m['value']);
            return '';
        }, $source);

        // Handle block @section('name') ... @endsection
        $sectionPattern = '/@section\((?:\'|")(?<name>.+?)(?:\'|")\)(?<content>.*?)@endsection/s';
        $source = (string)preg_replace_callback($sectionPattern, function (array $m) use (&$sections) {
            $sections[$m['name']] = $m['content'];
            return '';
        }, $source);
        return [(string)$source, $sections];
    }

    /**
     * @param array<string, string> $sections
     */
    private function replaceYields(string $source, array $sections): string
    {
        // Support @yield('section', 'default value') with optional second argument
        $pattern = '/@yield\(\s*(?:\'|")(?<section>[^"\']+)(?:\'|")\s*(?:,\s*(?<default>(?:[^()]+|\((?:[^()]+|\((?:[^()]+|\([^()]*\))*\))*\))*))?\s*\)/';
        return (string)preg_replace_callback($pattern, function (array $m) use ($sections) {
            $sectionName = $m['section'];
            if (isset($sections[$sectionName])) {
                return $sections[$sectionName];
            }
            // Use default value if provided, otherwise empty
            $default = isset($m['default']) && trim($m['default']) !== '' ? trim($m['default']) : '';
            // Strip surrounding quotes from simple string defaults
            if (preg_match('/^["\'](.+)["\']$/', $default, $dq)) {
                $default = $dq[1];
            }
            return $default;
        }, $source);
    }
    public function startPush(string $section): void {
        ob_start();
        $this->pushStacks[$section][] = '__PUSH_START__';
    }
    public function stopPush(): void {
        $content = ob_get_clean();
        if ($content === false) return;
        foreach ($this->pushStacks as $name => &$stack) {
            if (!empty($stack) && end($stack) === '__PUSH_START__') {
                array_pop($stack);
                $stack[] = $content;
                return;
            }
        }
    }
    public function startPrepend(string $section): void {
        ob_start();
        $this->prependStacks[$section][] = '__PREPEND_START__';
    }
    public function stopPrepend(): void {
        $content = ob_get_clean();
        if ($content === false) return;
        foreach ($this->prependStacks as $name => &$stack) {
            if (!empty($stack) && end($stack) === '__PREPEND_START__') {
                array_pop($stack);
                $stack[] = $content;
                return;
            }
        }
    }
    public function yieldPush(string $section, string $default = ''): string {
        $placeholder = "<!-- __ML_STACK_{$section}__ -->";
        $this->stackPlaceholders[$placeholder] = $section;
        return $placeholder;
    }
    private function replaceStackPlaceholders(string $content): string {
        foreach ($this->stackPlaceholders as $placeholder => $section) {
            $prepends = $this->prependStacks[$section] ?? [];
            $pushes = $this->pushStacks[$section] ?? [];
            $output = '';
            foreach (array_reverse($prepends) as $item) $output .= $item;
            foreach ($pushes as $item) $output .= $item;
            $content = str_replace($placeholder, $output, $content);
        }
        return $content;
    }
    public function addOnceHash(string $hash): bool {
        if (isset($this->onceHashes[$hash])) return false;
        $this->onceHashes[$hash] = true;
        return true;
    }
    public function addLoop(mixed $data): void {
        $parent = end($this->loopStack) ?: null;
        $this->loopStack[] = \MonkeysLegion\Template\Support\Loop::start($data, $parent);
    }
    public function popLoop(): void {
        array_pop($this->loopStack);
    }
    public function getLastLoop(): ?\MonkeysLegion\Template\Support\Loop {
        return end($this->loopStack) ?: null;
    }

    /**
     * @return never
     */
    private function handleViewException(Throwable $e, int $level): never
    {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }

        if ($e instanceof \MonkeysLegion\Template\Exceptions\ViewException) {
            throw $e;
        }

        $exceptionFile = $e->getFile();
        $exceptionLine = $e->getLine();

        // Try source map first (new v2 approach)
        $sourceMapPath = $exceptionFile . '.map';
        if (is_file($sourceMapPath)) {
            $mapData = file_get_contents($sourceMapPath);
            if ($mapData !== false) {
                $sourceMap = SourceMap::deserialize($mapData);
                $mapping   = $sourceMap->resolve($exceptionLine);

                if ($mapping !== null) {
                    $viewException = new ViewException(
                        $e->getMessage() . ' (View: ' . basename($mapping['sourcePath']) . ')',
                        0,
                        1,
                        $mapping['sourcePath'],
                        $mapping['sourceLine'],
                        $e,
                    );
                    $viewException->setSourceMap($sourceMap);
                    throw $viewException;
                }
            }
        }

        // Fallback: legacy PATH marker approach
        if (is_file($exceptionFile)) {
            $handle = fopen($exceptionFile, 'r');
            if ($handle) {
                $header = fread($handle, 512);
                fclose($handle);

                if ($header !== false) {
                    // Safe construction
                    $pathStartMarker = '/' . '**PATH ';
                    $pathEndMarker = ' ENDPATH**' . '/';

                    $startPos = strpos($header, $pathStartMarker);

                    if ($startPos !== false) {
                        $startPos += strlen($pathStartMarker);
                        $endPos = strpos($header, $pathEndMarker, $startPos);

                        if ($endPos !== false) {
                            $originalPath = substr($header, $startPos, $endPos - $startPos);
                            $mapOffset = 2; // Default

                            // Check for attribute bag usage
                            if (str_contains($header, 'AttributeBag;') && str_contains($header, '?>')) {
                                $mapOffset = 4;
                            }

                            $originalLine = max(1, $exceptionLine - $mapOffset);

                            throw new ViewException(
                                $e->getMessage() . ' (View: ' . basename($originalPath) . ')',
                                0,
                                1,
                                $originalPath,
                                $originalLine,
                                $e,
                            );
                        }
                    }
                }
            }
        }

        throw new RuntimeException(
            'Error rendering template: ' . $e->getMessage(),
            0,
            $e,
        );
    }

    public function getRegistry(): \MonkeysLegion\Template\Support\DirectiveRegistry
    {
        return $this->registry ?? new \MonkeysLegion\Template\Support\DirectiveRegistry();
    }

    public function setRegistry(\MonkeysLegion\Template\Support\DirectiveRegistry $registry): void
    {
        $this->registry = $registry;
    }

    /**
     * Set the PSR-7 request for HTMX fragment rendering.
     */
    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    /**
     * Get the current PSR-7 request.
     */
    public function getRequest(): ?ServerRequestInterface
    {
        return $this->request;
    }

    /**
     * Check if the current request is an HTMX request.
     */
    public function isHtmxRequest(): bool
    {
        if ($this->request === null) {
            return false;
        }

        return $this->request->hasHeader('HX-Request');
    }

    /**
     * Register a function component (closure-based).
     */
    public function registerFunctionComponent(string $name, callable $fn): void
    {
        $this->functionComponents[$name] = $fn;
    }

    /**
     * Get a registered function component.
     */
    public function getFunctionComponent(string $name): ?callable
    {
        return $this->functionComponents[$name] ?? null;
    }

    /**
     * Check if a function component is registered.
     */
    public function hasFunctionComponent(string $name): bool
    {
        return isset($this->functionComponents[$name]);
    }

    /**
     * Register a listener for the rendering event (before render).
     */
    public function onRendering(callable $listener): void
    {
        $this->renderingListeners[] = $listener;
    }

    /**
     * Register a listener for the rendered event (after render).
     */
    public function onRendered(callable $listener): void
    {
        $this->renderedListeners[] = $listener;
    }

    /**
     * Dispatch the rendered event and return the (possibly modified) output.
     *
     * @param array<string, mixed> $data
     */
    private function dispatchRendered(string $name, array $data, string $output): string
    {
        if (empty($this->renderedListeners)) {
            return $output;
        }

        $event = new Events\ViewRendered($name, $data, $output);
        foreach ($this->renderedListeners as $listener) {
            $listener($event);
        }

        return $event->output;
    }
}
