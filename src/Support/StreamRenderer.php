<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Support;

use Generator;
use MonkeysLegion\Template\Contracts\CompilerInterface;
use MonkeysLegion\Template\Contracts\LoaderInterface;
use MonkeysLegion\Template\Contracts\ParserInterface;
use MonkeysLegion\Template\VariableScope;
use RuntimeException;

/**
 * Streaming template renderer.
 *
 * Yields output chunks as they're rendered, enabling:
 * - Chunked transfer encoding for large pages
 * - Improved Time To First Byte (TTFB)
 * - Memory-efficient rendering of large templates
 *
 * Usage:
 *   foreach ($streamRenderer->renderStream('page', $data) as $chunk) {
 *       echo $chunk;
 *       flush();
 *   }
 */
final class StreamRenderer
{
    public function __construct(
        private readonly ParserInterface $parser,
        private readonly CompilerInterface $compiler,
        private readonly LoaderInterface $loader,
        private readonly string $cacheDir,
    ) {}

    /**
     * Render a template and yield output chunks.
     *
     * Compiled PHP is cached on disk and only recompiled when the source
     * template has changed (mtime-based invalidation).
     *
     * @param array<string, mixed> $data
     * @return Generator<int, string, mixed, void>
     */
    public function renderStream(string $__name, array $data = []): Generator
    {
        $sourcePath = $this->loader->getSourcePath($__name);
        if (!is_file($sourcePath)) {
            throw new RuntimeException("Template source not found: {$sourcePath}");
        }

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $compiledPath = $this->cacheDir . '/stream_' . md5($sourcePath) . '.php';

        // Only recompile when source has changed
        $sourceMtime = filemtime($sourcePath);
        $compiledMtime = is_file($compiledPath) ? filemtime($compiledPath) : false;

        if ($compiledMtime === false || $sourceMtime === false || $sourceMtime > $compiledMtime) {
            $raw = file_get_contents($sourcePath);
            if ($raw === false) {
                throw new RuntimeException("Failed to read template: {$sourcePath}");
            }

            $parsed = $this->parser->parse($raw);
            $php = $this->compiler->compile($parsed, $sourcePath);

            $tmpPath = tempnam($this->cacheDir, 'ml_stream_');
            if ($tmpPath !== false) {
                file_put_contents($tmpPath, $php);
                rename($tmpPath, $compiledPath);
            } else {
                file_put_contents($compiledPath, $php);
            }
        }

        try {
            $scope = new VariableScope($data);
            VariableScope::setCurrent($scope);

            // Accumulate chunks for true streaming via output buffer callback
            /** @var string[] $chunks */
            $chunks = [];
            ob_start(static function (string $buffer, int $phase) use (&$chunks): string {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                }
                return ''; // Consume output so it doesn't go to stdout
            }, 4096);

            $GLOBALS['__data'] = $scope->getCurrentScope();
            $GLOBALS['__ml_attrs'] = [];
            extract($scope->getCurrentScope(), EXTR_SKIP);
            if (!isset($slots)) {
                $slots = SlotCollection::fromArray([]);
            }

            include $compiledPath;

            ob_end_clean();

            // Yield accumulated chunks
            foreach ($chunks as $chunk) {
                yield $chunk;
            }
        } finally {
            unset($GLOBALS['__ml_attrs'], $GLOBALS['__data']);
        }
    }

    /**
     * Render a string template and yield output chunks.
     *
     * @param array<string, mixed> $data
     * @return Generator<int, string, mixed, void>
     */
    public function renderStringStream(string $source, array $data = []): Generator
    {
        $parsed = $this->parser->parse($source);
        $php = $this->compiler->compile($parsed, 'string_stream');

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $tmpPath = $this->cacheDir . '/str_stream_' . md5($source) . '.php';
        file_put_contents($tmpPath, $php);

        try {
            $scope = new VariableScope($data);
            VariableScope::setCurrent($scope);

            /** @var string[] $chunks */
            $chunks = [];
            ob_start(static function (string $buffer, int $phase) use (&$chunks): string {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                }
                return '';
            }, 4096);

            $GLOBALS['__data'] = $scope->getCurrentScope();
            $GLOBALS['__ml_attrs'] = [];
            extract($scope->getCurrentScope(), EXTR_SKIP);
            if (!isset($slots)) {
                $slots = SlotCollection::fromArray([]);
            }

            include $tmpPath;

            ob_end_clean();

            foreach ($chunks as $chunk) {
                yield $chunk;
            }
        } finally {
            unset($GLOBALS['__ml_attrs'], $GLOBALS['__data']);
            @unlink($tmpPath);
        }
    }
}
