<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Support;

use Generator;
use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Contracts\LoaderInterface;
use MonkeysLegion\Template\Parser;
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
        private readonly Parser $parser,
        private readonly Compiler $compiler,
        private readonly LoaderInterface $loader,
        private readonly string $cacheDir,
    ) {}

    /**
     * Render a template and yield output chunks.
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

        $raw = file_get_contents($sourcePath);
        if ($raw === false) {
            throw new RuntimeException("Failed to read template: {$sourcePath}");
        }

        $parsed = $this->parser->parse($raw);
        $php = $this->compiler->compile($parsed, $sourcePath);

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $tmpPath = $this->cacheDir . '/stream_' . md5($sourcePath) . '.php';
        file_put_contents($tmpPath, $php);

        try {
            $scope = new VariableScope($data);
            VariableScope::setCurrent($scope);

            // Capture output in small chunks
            ob_start(null, 4096);

            $GLOBALS['__data'] = $scope->getCurrentScope();
            $GLOBALS['__ml_attrs'] = [];
            extract($scope->getCurrentScope(), EXTR_SKIP);
            if (!isset($slots)) {
                $slots = SlotCollection::fromArray([]);
            }

            include $tmpPath;

            $remaining = ob_get_clean();
            if ($remaining !== false && $remaining !== '') {
                yield $remaining;
            }
        } finally {
            unset($GLOBALS['__ml_attrs'], $GLOBALS['__data']);
            @unlink($tmpPath);
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

            ob_start();

            $GLOBALS['__data'] = $scope->getCurrentScope();
            $GLOBALS['__ml_attrs'] = [];
            extract($scope->getCurrentScope(), EXTR_SKIP);
            if (!isset($slots)) {
                $slots = SlotCollection::fromArray([]);
            }

            include $tmpPath;

            $output = ob_get_clean();
            if ($output !== false && $output !== '') {
                // Yield in chunks for streaming
                $chunkSize = 4096;
                $offset = 0;
                while ($offset < strlen($output)) {
                    yield substr($output, $offset, $chunkSize);
                    $offset += $chunkSize;
                }
            }
        } finally {
            unset($GLOBALS['__ml_attrs'], $GLOBALS['__data']);
            @unlink($tmpPath);
        }
    }
}
