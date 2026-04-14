<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use MonkeysLegion\Template\Contracts\CompilerInterface;
use MonkeysLegion\Template\Contracts\LoaderInterface;
use MonkeysLegion\Template\Contracts\ParserInterface;
use MonkeysLegion\Template\Exceptions\ParseException;
use MonkeysLegion\Template\Exceptions\ViewException;
use MonkeysLegion\Template\Support\DirectiveRegistry;
use RuntimeException;
use Throwable;

final class Renderer
{
    private ParserInterface $parser;
    private CompilerInterface $compiler;
    private LoaderInterface $loader;
    private bool $cacheEnabled;
    private string $cacheDir;
    private array $pushStacks = [];
    private array $prependStacks = [];
    private array $onceHashes = [];
    private array $stackPlaceholders = [];
    private array $loopStack = [];
    private array $sections = [];
    public function __construct(
        ParserInterface $parser,
        CompilerInterface $compiler,
        LoaderInterface $loader,
        bool $cacheEnabled = true,
        string $cacheDir = '',
        private ?DirectiveRegistry $registry = null
    ) {
        $this->parser       = $parser;
        $this->compiler     = $compiler;
        $this->loader       = $loader;
        $this->cacheEnabled = $cacheEnabled;
        $this->registry     = $registry ?? new DirectiveRegistry();
        $this->cacheDir     = $cacheDir !== ''
            ? rtrim($cacheDir, DIRECTORY_SEPARATOR)
            : (function_exists('base_path') ?
                call_user_func('base_path', 'var/cache/views') : sys_get_temp_dir() . '/monkeyslegion/views');
    }

    public function render(string $__name, array $__data = [], bool $isInternal = false): string
    {
        if (!$isInternal) {
            $this->sections = [];
        }

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

            $__compiledPath = $this->getCompiledPath($__name, $__sourcePath);

            // Compile if cache is disabled or expired
            if (!$this->cacheEnabled || !is_file($__compiledPath) || filemtime($__sourcePath) > filemtime($__compiledPath)) {
                if (!is_dir($this->cacheDir)) {
                    mkdir($this->cacheDir, 0755, true);
                }
                $php = $this->compiler->compile($__raw, $__sourcePath);
                file_put_contents($__compiledPath, $php);
            }

            $level = ob_get_level();
            ob_start();

            try {
                $GLOBALS['__data']     = $scope->getCurrentScope();
                $GLOBALS['__ml_attrs'] = [];
                $__ml_sections = &$this->sections;

                extract($scope->getCurrentScope(), EXTR_SKIP);
                if (!isset($slots)) {
                    $slots = \MonkeysLegion\Template\Support\SlotCollection::fromArray([]);
                }

                include $__compiledPath;

                $__templateOutput = ob_get_clean();

                if ($__templateOutput === false) {
                    throw new RuntimeException(sprintf('Renderer buffer closed for [%s].', $__name));
                }

                if (isset($__ml_extends) && $__ml_extends !== null) {
                    return $this->render(str_replace('/', '.', $__ml_extends), $__data, true);
                }

                return $this->replaceStackPlaceholders($__templateOutput);
            } finally {
                unset($GLOBALS['__ml_attrs'], $GLOBALS['__data']);
                if (!$this->cacheEnabled) {
                    @unlink($__compiledPath);
                }
            }
        } catch (Throwable $e) {
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

            $attributeBag = new \MonkeysLegion\Template\Support\AttributeBag($passedAttrs);
            $passedAttrs['attributes'] = $attributeBag;

            $scope->createIsolatedScope($passedAttrs, $props);
            $__compiledPath = $this->getCompiledPathForComponent($__path);

            if (!$this->cacheEnabled || !is_file($__compiledPath) || filemtime($__path) > filemtime($__compiledPath)) {
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
            if (isset($scopedData['attributes'])) {
                $scopedData['attrs'] = $scopedData['attributes'];
            }

            extract($scopedData, EXTR_SKIP);
            include $__compiledPath;

            $output = ob_get_clean();
            if ($output === false) {
                throw new RuntimeException("Component buffer closed: {$__path}");
            }
            return $output;
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


    public function getRegistry(): DirectiveRegistry
    {
        return $this->registry;
    }

    public function setRegistry(DirectiveRegistry $registry): void
    {
        $this->registry = $registry;
    }
}
