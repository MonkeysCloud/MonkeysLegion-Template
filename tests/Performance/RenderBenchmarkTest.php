<?php

declare(strict_types=1);

namespace Tests\Performance;

use MonkeysLegion\Template\Cache\CompiledTemplatePool;
use MonkeysLegion\Template\Cache\FilesystemViewCache;
use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Contracts\LoaderInterface;
use MonkeysLegion\Template\Parser;
use MonkeysLegion\Template\Renderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Performance benchmark tests for template rendering.
 *
 * These tests measure execution time and memory usage for key operations.
 * They don't assert strict thresholds (hardware-dependent), but report
 * metrics and verify relative improvements.
 */
final class RenderBenchmarkTest extends TestCase
{
    private string $cacheDir;
    private string $sourceDir;

    /** @var array<string, string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ml_bench_cache_' . uniqid();
        $this->sourceDir = sys_get_temp_dir() . '/ml_bench_src_' . uniqid();
        mkdir($this->cacheDir, 0755, true);
        mkdir($this->sourceDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cacheDir);
        $this->removeDir($this->sourceDir);
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

    private function createSource(string $name, string $content): string
    {
        $path = $this->sourceDir . '/' . str_replace('.', '/', $name) . '.ml.php';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
        $this->files[$name] = $path;
        return $path;
    }

    private function createRenderer(): Renderer
    {
        $files = &$this->files;
        $loader = $this->createMock(LoaderInterface::class);
        $loader->method('getSourcePath')->willReturnCallback(function (string $name) use (&$files) {
            if (isset($files[$name])) {
                return $files[$name];
            }
            throw new \RuntimeException("View [$name] not found.");
        });

        $parser = new Parser();
        $compiler = new Compiler($parser);

        return new Renderer($parser, $compiler, $loader, true, $this->cacheDir);
    }

    #[Test]
    public function benchmark_simple_template_render(): void
    {
        $renderer = $this->createRenderer();
        $this->createSource('simple', '<h1>{{ $title }}</h1><p>{{ $body }}</p>');

        $data = ['title' => 'Hello', 'body' => 'World'];
        $iterations = 1000;

        // Warm up (first render compiles)
        $renderer->render('simple', $data);

        // Benchmark cached renders
        $start = hrtime(true);
        $memBefore = memory_get_usage();

        for ($i = 0; $i < $iterations; $i++) {
            $renderer->render('simple', $data);
        }

        $elapsed = (hrtime(true) - $start) / 1_000_000; // ms
        $memDelta = memory_get_usage() - $memBefore;
        $perRender = $elapsed / $iterations;

        // Report
        fwrite(STDERR, sprintf(
            "\n  [PERF] simple_render: %d iterations in %.2f ms (%.3f ms/render, +%d bytes memory)\n",
            $iterations,
            $elapsed,
            $perRender,
            $memDelta,
        ));

        // Sanity: should complete in reasonable time
        $this->assertLessThan(5000, $elapsed, "1000 simple renders should complete in under 5 seconds");
    }

    #[Test]
    public function benchmark_template_with_loop(): void
    {
        $renderer = $this->createRenderer();
        $template = <<<'TPL'
<ul>
@foreach($items as $item)
    <li>{{ $item }}</li>
@endforeach
</ul>
TPL;
        $this->createSource('loop', $template);

        $data = ['items' => range(1, 100)];
        $iterations = 500;

        $renderer->render('loop', $data);

        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $renderer->render('loop', $data);
        }
        $elapsed = (hrtime(true) - $start) / 1_000_000;
        $perRender = $elapsed / $iterations;

        fwrite(STDERR, sprintf(
            "\n  [PERF] loop_render (100 items): %d iterations in %.2f ms (%.3f ms/render)\n",
            $iterations,
            $elapsed,
            $perRender,
        ));

        $this->assertLessThan(10000, $elapsed);
    }

    #[Test]
    public function benchmark_compilation_speed(): void
    {
        $parser = new Parser();
        $compiler = new Compiler($parser);

        // Complex template with many directives
        $template = <<<'TPL'
<html>
<head><title>{{ $title }}</title></head>
<body>
@if($showHeader)
    <header>{{ $header }}</header>
@endif
<main>
@foreach($sections as $section)
    <section>
        <h2>{{ $section['title'] }}</h2>
        @if($section['highlight'])
            <div class="highlight">{{ $section['content'] }}</div>
        @else
            <div>{{ $section['content'] }}</div>
        @endif
    </section>
@endforeach
</main>
@unless($hideFooter)
    <footer>&copy; {{ $year }}</footer>
@endunless
</body>
</html>
TPL;

        $iterations = 1000;

        // Warm up
        $compiler->compile($template, 'bench.ml.php');

        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $compiler->compile($template, 'bench.ml.php');
        }
        $elapsed = (hrtime(true) - $start) / 1_000_000;
        $perCompile = $elapsed / $iterations;

        fwrite(STDERR, sprintf(
            "\n  [PERF] compile: %d iterations in %.2f ms (%.3f ms/compile)\n",
            $iterations,
            $elapsed,
            $perCompile,
        ));

        $this->assertLessThan(10000, $elapsed);
    }

    #[Test]
    public function benchmark_filesystem_cache_freshness_check(): void
    {
        $cache = new FilesystemViewCache($this->cacheDir);
        $source = $this->createSource('cached', '<p>Hello</p>');

        // Pre-cache
        $cache->put('cached', $source, '<?php echo "Hello"; ?>');

        $iterations = 10000;
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $cache->isFresh('cached', $source);
        }
        $elapsed = (hrtime(true) - $start) / 1_000_000;
        $perCheck = $elapsed / $iterations;

        fwrite(STDERR, sprintf(
            "\n  [PERF] isFresh: %d checks in %.2f ms (%.4f ms/check)\n",
            $iterations,
            $elapsed,
            $perCheck,
        ));

        $this->assertLessThan(5000, $elapsed);
    }

    #[Test]
    public function benchmark_production_mode_vs_dev_mode(): void
    {
        $source = $this->createSource('prod_test', '<p>{{ $name }}</p>');

        $devCache = new FilesystemViewCache($this->cacheDir, checkMtime: true);
        $prodCache = new FilesystemViewCache($this->cacheDir, checkMtime: false);

        // Pre-cache both
        $devCache->put('prod_test', $source, '<?php echo "Hello"; ?>');

        $iterations = 10000;

        // Dev mode
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $devCache->isFresh('prod_test', $source);
        }
        $devElapsed = (hrtime(true) - $start) / 1_000_000;

        // Production mode
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $prodCache->isFresh('prod_test', $source);
        }
        $prodElapsed = (hrtime(true) - $start) / 1_000_000;

        $speedup = $devElapsed / max($prodElapsed, 0.01);

        fwrite(STDERR, sprintf(
            "\n  [PERF] dev: %.2f ms vs prod: %.2f ms (%.1fx speedup)\n",
            $devElapsed,
            $prodElapsed,
            $speedup,
        ));

        // Production should be faster (no filemtime calls)
        $this->assertLessThan($devElapsed, $prodElapsed, "Production mode should be faster than dev mode");
    }

    #[Test]
    public function benchmark_memory_pool_vs_no_pool(): void
    {
        $pool = new CompiledTemplatePool();

        $iterations = 100000;

        // Without pool: simulate repeated filemtime + is_file
        $source = $this->createSource('pool_test', '<p>test</p>');
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $_ = is_file($source);
            $_ = filemtime($source);
        }
        $nopoolElapsed = (hrtime(true) - $start) / 1_000_000;

        // With pool: memory lookup
        $pool->put('pool_test', $source, (float) filemtime($source));
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $_ = $pool->has('pool_test');
            $_ = $pool->getPath('pool_test');
        }
        $poolElapsed = (hrtime(true) - $start) / 1_000_000;

        $speedup = $nopoolElapsed / max($poolElapsed, 0.01);

        fwrite(STDERR, sprintf(
            "\n  [PERF] no_pool (fs): %.2f ms vs pool (mem): %.2f ms (%.1fx speedup)\n",
            $nopoolElapsed,
            $poolElapsed,
            $speedup,
        ));

        // Memory pool operations should complete quickly regardless
        $this->assertLessThan(1000, $poolElapsed, "Pool lookups should complete in under 1 second for 100k iterations");
    }

    #[Test]
    public function benchmark_filter_pipeline(): void
    {
        $renderer = $this->createRenderer();

        // Template with filter chain
        $this->createSource('filters', '{{ $name | upper | truncate(10) }}');

        $data = ['name' => 'The quick brown fox jumps over the lazy dog'];
        $iterations = 1000;

        $renderer->render('filters', $data);

        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $renderer->render('filters', $data);
        }
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        fwrite(STDERR, sprintf(
            "\n  [PERF] filter_pipeline: %d iterations in %.2f ms (%.3f ms/render)\n",
            $iterations,
            $elapsed,
            $elapsed / $iterations,
        ));

        $this->assertLessThan(5000, $elapsed);
    }

    #[Test]
    public function benchmark_memory_usage_large_data(): void
    {
        $renderer = $this->createRenderer();
        $template = <<<'TPL'
<table>
@foreach($rows as $row)
    <tr>
    @foreach($row as $cell)
        <td>{{ $cell }}</td>
    @endforeach
    </tr>
@endforeach
</table>
TPL;
        $this->createSource('large', $template);

        // 1000 rows × 10 columns
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = array_map(fn($j) => "Cell-{$i}-{$j}", range(0, 9));
        }
        $data = ['rows' => $rows];

        $memBefore = memory_get_peak_usage();
        $start = hrtime(true);

        $output = $renderer->render('large', $data);

        $elapsed = (hrtime(true) - $start) / 1_000_000;
        $memPeak = memory_get_peak_usage() - $memBefore;

        fwrite(STDERR, sprintf(
            "\n  [PERF] large_data (1000×10 table): %.2f ms, +%.2f KB peak memory, %d bytes output\n",
            $elapsed,
            $memPeak / 1024,
            strlen($output),
        ));

        // Output should be non-trivial
        $this->assertGreaterThan(10000, strlen($output));
        $this->assertLessThan(10000, $elapsed, "Large table should render in under 10 seconds");
    }
}
