<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use MonkeysLegion\Template\Exceptions\ViewException;
use RuntimeException;
use Throwable;

final class Renderer
{
    private \MonkeysLegion\Template\Contracts\ParserInterface $parser;
    private \MonkeysLegion\Template\Contracts\CompilerInterface $compiler;
    private \MonkeysLegion\Template\Contracts\LoaderInterface $loader;
    private bool $cacheEnabled;
    private string $cacheDir;
    private array $pushStacks = [];
    private array $prependStacks = [];
    private array $onceHashes = [];
    private array $stackPlaceholders = [];
    private array $loopStack = [];
    public function __construct(
        \MonkeysLegion\Template\Contracts\ParserInterface $parser,
        \MonkeysLegion\Template\Contracts\CompilerInterface $compiler,
        \MonkeysLegion\Template\Contracts\LoaderInterface $loader,
        bool $cacheEnabled = true,
        string $cacheDir = '',
        private ?\MonkeysLegion\Template\Support\DirectiveRegistry $registry = null
    ) {
        $this->parser       = $parser;
        $this->compiler     = $compiler;
        $this->loader       = $loader;
        $this->cacheEnabled = $cacheEnabled;
        $this->registry     = $registry ?? new \MonkeysLegion\Template\Support\DirectiveRegistry();
        $this->cacheDir     = $cacheDir !== ''
            ? rtrim($cacheDir, DIRECTORY_SEPARATOR)
            : (function_exists('base_path') ?
                call_user_func('base_path', 'var/cache/views') : sys_get_temp_dir() . '/monkeyslegion/views');
    }

    public function render(string $__name, array $__data = []): string
    {
        try {
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

            $__compiledPath = $this->getCompiledPath($__name, $__sourcePath);

            if ($this->cacheEnabled) {
                if (!is_dir($this->cacheDir)) {
                    mkdir($this->cacheDir, 0755, true);
                }

                if (
                    !is_file($__compiledPath)
                    || filemtime($__sourcePath) > filemtime($__compiledPath)
                ) {
                    $php = $this->compiler->compile($__raw, $__sourcePath);
                    file_put_contents($__compiledPath, $php);
                }

                $level = ob_get_level();
                ob_start();

                try {
                    $GLOBALS['__data']     = $scope->getCurrentScope();
                    $GLOBALS['__ml_attrs'] = [];
                    extract($scope->getCurrentScope(), EXTR_SKIP);
                    if (!isset($slots)) {
                        $slots = \MonkeysLegion\Template\Support\SlotCollection::fromArray([]);
                    }

                    include $__compiledPath;
                    $__templateOutput = ob_get_clean();

                    if ($__templateOutput === false) {
                        throw new RuntimeException(sprintf(
                            'Renderer buffer was closed while rendering view [%s].',
                            $__name
                        ));
                    }

                    return $this->replaceStackPlaceholders($__templateOutput);
                } catch (Throwable $e) {
                    $this->handleViewException($e, $level);
                    throw $e;
                } finally {
                    unset($GLOBALS['__ml_attrs'], $GLOBALS['__data']);
                }
            }

            $php = $this->compiler->compile($__raw, $__sourcePath);
            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0755, true);
            }
            $tmpCompiledPath = $__compiledPath;
            file_put_contents($tmpCompiledPath, $php);

            $level = ob_get_level();
            ob_start();

            try {
                $GLOBALS['__data']     = $scope->getCurrentScope();
                $GLOBALS['__ml_attrs'] = [];
                extract($scope->getCurrentScope(), EXTR_SKIP);
                if (!isset($slots)) {
                    $slots = \MonkeysLegion\Template\Support\SlotCollection::fromArray([]);
                }

                include $tmpCompiledPath;
                $__templateOutput = ob_get_clean();

                if ($__templateOutput === false) {
                    throw new RuntimeException(sprintf(
                        'Renderer buffer was closed while rendering view [%s] (no-cache).',
                        $__name
                    ));
                }

                return $this->replaceStackPlaceholders($__templateOutput);
            } catch (Throwable $e) {
                $this->handleViewException($e, $level);
                throw $e;
            } finally {
                unset($GLOBALS['__ml_attrs'], $GLOBALS['__data']);
                @unlink($tmpCompiledPath);
            }
        } catch (Throwable $e) {
            unset($GLOBALS['__ml_attrs'], $GLOBALS['__data']);
            throw $e;
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
            throw $e;
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

    private function getCompiledPath(string $name, string $sourcePath): string
    {
        $file = str_replace(['.', '/'], '_', $name) . '_' . md5($sourcePath) . '.php';
        return $this->cacheDir . DIRECTORY_SEPARATOR . $file;
    }

    private function extractSections(string $source): array
    {
        $sections = [];
        if (preg_match('/@extends\((?:\'|")(?<view>.+?)(?:\'|")\)/', $source, $m)) {
            $sections['__extends'] = $m['view'];
            $source = (string)preg_replace('/@extends\((?:\'|").+?(?:\'|")\)/', '', $source, 1);
        }
        $sectionPattern = '/@section\((?:\'|")(?<name>.+?)(?:\'|")\)(?<content>.*?)@endsection/s';
        $source = (string)preg_replace_callback($sectionPattern, function (array $m) use (&$sections) {
            $sections[$m['name']] = $m['content'];
            return '';
        }, $source);
        return [(string)$source, $sections];
    }

    private function replaceYields(string $source, array $sections): string
    {
        $pattern = '/@yield\((?:\'|")(?<section>.+?)(?:\'|")\)/';
        return (string)preg_replace_callback($pattern, function (array $m) use ($sections) {
            $sectionName = $m['section'];
            return $sections[$sectionName] ?? '';
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
    private function handleViewException(Throwable $e, int $level): void
    {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }

        if ($e instanceof \MonkeysLegion\Template\Exceptions\ViewException) {
            throw $e;
        }

        $exceptionFile = $e->getFile();
        $exceptionLine = $e->getLine();

        if (is_file($exceptionFile)) {
            $handle = fopen($exceptionFile, 'r');
            if ($handle) {
                $header = fread($handle, 512);
                fclose($handle);

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

                        throw new \MonkeysLegion\Template\Exceptions\ViewException(
                            $e->getMessage() . " (View: " . basename($originalPath) . ")",
                            0,
                            1,
                            $originalPath,
                            $originalLine,
                            $e
                        );
                    }
                }
            }
        }

        throw new RuntimeException(
            "Error rendering template: " . $e->getMessage(),
            0,
            $e
        );
    }

    public function getRegistry(): \MonkeysLegion\Template\Support\DirectiveRegistry
    {
        return $this->registry;
    }

    public function setRegistry(\MonkeysLegion\Template\Support\DirectiveRegistry $registry): void
    {
        $this->registry = $registry;
    }
}
