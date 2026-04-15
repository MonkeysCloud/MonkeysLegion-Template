<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Contracts\LoaderInterface;
use MonkeysLegion\Template\Parser;
use MonkeysLegion\Template\Support\StreamRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for StreamRenderer — generator-based streaming output.
 */
final class StreamRendererTest extends TestCase
{
    private string $tempDir;
    private string $cacheDir;
    private StreamRenderer $stream;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ml_stream_test_' . uniqid();
        $this->cacheDir = sys_get_temp_dir() . '/ml_stream_cache_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->cacheDir, 0755, true);

        $parser = new Parser();
        $compiler = new Compiler($parser);

        $loader = $this->createMock(LoaderInterface::class);
        $loader->method('getSourcePath')->willReturnCallback(function (string $name): string {
            return $this->tempDir . '/' . $name . '.ml.php';
        });

        $this->stream = new StreamRenderer($parser, $compiler, $loader, $this->cacheDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        $this->removeDir($this->cacheDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    #[Test]
    public function renders_simple_template_stream(): void
    {
        file_put_contents($this->tempDir . '/hello.ml.php', '<h1>Hello World</h1>');

        $chunks = [];
        foreach ($this->stream->renderStream('hello') as $chunk) {
            $chunks[] = $chunk;
        }

        $output = implode('', $chunks);
        $this->assertStringContainsString('Hello World', $output);
    }

    #[Test]
    public function renders_with_variables(): void
    {
        file_put_contents($this->tempDir . '/greet.ml.php', '<p>Hello {{ $name }}</p>');

        $chunks = [];
        foreach ($this->stream->renderStream('greet', ['name' => 'World']) as $chunk) {
            $chunks[] = $chunk;
        }

        $output = implode('', $chunks);
        $this->assertStringContainsString('World', $output);
    }

    #[Test]
    public function yields_at_least_one_chunk(): void
    {
        file_put_contents($this->tempDir . '/simple.ml.php', '<p>Content</p>');

        $chunkCount = 0;
        foreach ($this->stream->renderStream('simple') as $chunk) {
            $chunkCount++;
            $this->assertNotEmpty($chunk);
        }

        $this->assertGreaterThanOrEqual(1, $chunkCount);
    }

    #[Test]
    public function renderStringStream_works(): void
    {
        $chunks = [];
        foreach ($this->stream->renderStringStream('<p>{{ $msg }}</p>', ['msg' => 'Streamed']) as $chunk) {
            $chunks[] = $chunk;
        }

        $output = implode('', $chunks);
        $this->assertStringContainsString('Streamed', $output);
    }

    #[Test]
    public function renderStringStream_empty(): void
    {
        $chunks = [];
        foreach ($this->stream->renderStringStream('') as $chunk) {
            $chunks[] = $chunk;
        }

        // May yield nothing or empty
        $output = implode('', $chunks);
        $trimmed = trim($output);
        $this->assertSame('', $trimmed);
    }

    #[Test]
    public function stream_is_generator(): void
    {
        file_put_contents($this->tempDir . '/gen.ml.php', '<p>Generator</p>');

        $generator = $this->stream->renderStream('gen');

        $this->assertInstanceOf(\Generator::class, $generator);
    }
}
