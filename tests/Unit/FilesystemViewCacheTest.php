<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Cache\FilesystemViewCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilesystemViewCacheTest extends TestCase
{
    private string $cacheDir;
    private string $sourceDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ml_fscache_test_' . uniqid();
        $this->sourceDir = sys_get_temp_dir() . '/ml_fssrc_test_' . uniqid();
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
        $path = $this->sourceDir . '/' . $name . '.ml.php';
        file_put_contents($path, $content);
        return $path;
    }

    #[Test]
    public function isFresh_returns_false_when_not_cached(): void
    {
        $cache = new FilesystemViewCache($this->cacheDir);
        $source = $this->createSource('test', '<p>Hello</p>');

        $this->assertFalse($cache->isFresh('test', $source));
    }

    #[Test]
    public function put_then_isFresh_returns_true(): void
    {
        $cache = new FilesystemViewCache($this->cacheDir);
        $source = $this->createSource('test', '<p>Hello</p>');

        $compiled = $cache->put('test', $source, '<?php echo "Hello"; ?>');

        $this->assertTrue($cache->isFresh('test', $source));
        $this->assertFileExists($compiled);
    }

    #[Test]
    public function isFresh_returns_false_after_source_modified(): void
    {
        $cache = new FilesystemViewCache($this->cacheDir);
        $source = $this->createSource('test', '<p>Hello</p>');

        $cache->put('test', $source, '<?php echo "Hello"; ?>');
        $this->assertTrue($cache->isFresh('test', $source));

        // Touch the source with a future time
        touch($source, time() + 10);
        clearstatcache();

        $this->assertFalse($cache->isFresh('test', $source));
    }

    #[Test]
    public function production_mode_skips_mtime_check(): void
    {
        $cache = new FilesystemViewCache($this->cacheDir, checkMtime: false);
        $source = $this->createSource('test', '<p>Hello</p>');

        $cache->put('test', $source, '<?php echo "Hello"; ?>');

        // Modify source
        touch($source, time() + 10);
        clearstatcache();

        // Still fresh in production mode
        $this->assertTrue($cache->isFresh('test', $source));
        $this->assertTrue($cache->isProductionMode());
    }

    #[Test]
    public function forget_removes_compiled_file(): void
    {
        $cache = new FilesystemViewCache($this->cacheDir);
        $source = $this->createSource('test', '<p>Hello</p>');

        $compiled = $cache->put('test', $source, '<?php echo "Hello"; ?>');
        $this->assertFileExists($compiled);

        $cache->forget('test');
        $this->assertFileDoesNotExist($compiled);
    }

    #[Test]
    public function flush_removes_all(): void
    {
        $cache = new FilesystemViewCache($this->cacheDir);
        $source1 = $this->createSource('page1', '<p>1</p>');
        $source2 = $this->createSource('page2', '<p>2</p>');

        $cache->put('page1', $source1, '<?php echo 1; ?>');
        $cache->put('page2', $source2, '<?php echo 2; ?>');

        $cache->flush();

        $this->assertFalse($cache->isFresh('page1', $source1));
        $this->assertFalse($cache->isFresh('page2', $source2));
    }

    #[Test]
    public function atomic_write_produces_valid_file(): void
    {
        $cache = new FilesystemViewCache($this->cacheDir);
        $source = $this->createSource('atomic', '<p>Atomic</p>');
        $php = '<?php echo "Atomic write test"; ?>';

        $compiled = $cache->put('atomic', $source, $php);

        $this->assertSame($php, file_get_contents($compiled));
    }

    #[Test]
    public function dependency_tracking(): void
    {
        $cache = new FilesystemViewCache($this->cacheDir);
        $source = $this->createSource('layout', '<main>@yield("content")</main>');
        $dep = $this->createSource('partial', '<p>Partial</p>');

        $cache->put('layout', $source, '<?php echo "layout"; ?>');

        // Store dependencies
        $cache->putDependencies('layout', $source, [
            $dep => (int) filemtime($dep),
        ]);

        $this->assertTrue($cache->isFresh('layout', $source));

        // Modify the dependency
        touch($dep, time() + 10);
        clearstatcache();

        $this->assertFalse($cache->isFresh('layout', $source));
    }
}
