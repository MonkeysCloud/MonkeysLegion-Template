<?php

declare(strict_types=1);

namespace Tests\Integration;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Contracts\LoaderInterface;
use MonkeysLegion\Template\Parser;
use MonkeysLegion\Template\Renderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

/**
 * Tests for @cache/@endcache directive and fragment caching.
 */
final class CacheDirectiveTest extends TestCase
{
    private string $cacheDir;
    /** @var array<string, string> */
    private array $files = [];
    private Renderer $renderer;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ml_cache_dir_' . uniqid();
        mkdir($this->cacheDir, 0755, true);

        $loader = $this->createMock(LoaderInterface::class);
        $loader->method('getSourcePath')->willReturnCallback(function (string $name) {
            if (isset($this->files[$name])) {
                $path = $this->cacheDir . '/' . $name . '.ml.php';
                file_put_contents($path, $this->files[$name]);
                return $path;
            }
            throw new \RuntimeException("View [$name] not found.");
        });

        $parser = new Parser();
        $compiler = new Compiler($parser);

        $this->renderer = new Renderer($parser, $compiler, $loader, true, $this->cacheDir);
    }

    protected function tearDown(): void
    {
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
    public function cache_directive_without_store_renders_normally(): void
    {
        $this->files['no_store'] = <<<'TPL'
@cache('sidebar')
<div>Sidebar Content</div>
@endcache
TPL;

        $output = $this->renderer->render('no_store');
        $this->assertStringContainsString('Sidebar Content', $output);
    }

    #[Test]
    public function cache_directive_with_store_caches_output(): void
    {
        $store = $this->createArrayStore();
        $this->renderer->setFragmentCache($store);

        $this->files['cached'] = <<<'TPL'
@cache('test-key', 300)
<div>Cached Content</div>
@endcache
TPL;

        // First render — should miss and store
        $output1 = $this->renderer->render('cached');
        $this->assertStringContainsString('Cached Content', $output1);

        // Verify stored in cache
        $this->assertNotNull($store->get('ml_frag:test-key'));

        // Modify template source
        $this->files['cached'] = <<<'TPL'
@cache('test-key', 300)
<div>Modified Content</div>
@endcache
TPL;

        // Second render — should hit cache, return original
        // Need to clear the template pool to force recompile
        $this->renderer->getTemplatePool()->clear();
        $output2 = $this->renderer->render('cached');
        $this->assertStringContainsString('Cached Content', $output2);
        $this->assertStringNotContainsString('Modified', $output2);
    }

    #[Test]
    public function cache_directive_with_dynamic_key(): void
    {
        $store = $this->createArrayStore();
        $this->renderer->setFragmentCache($store);

        $this->files['dynamic_key'] = <<<'TPL'
@cache('user-' . $userId, 60)
<div>User {{ $userId }} Profile</div>
@endcache
TPL;

        $output = $this->renderer->render('dynamic_key', ['userId' => 42]);
        $this->assertStringContainsString('User 42 Profile', $output);
        $this->assertNotNull($store->get('ml_frag:user-42'));
    }

    #[Test]
    public function cache_directive_without_ttl(): void
    {
        $store = $this->createArrayStore();
        $this->renderer->setFragmentCache($store);

        $this->files['no_ttl'] = <<<'TPL'
@cache('forever')
<div>Permanent Content</div>
@endcache
TPL;

        $output = $this->renderer->render('no_ttl');
        $this->assertStringContainsString('Permanent Content', $output);
        $this->assertNotNull($store->get('ml_frag:forever'));
    }

    /**
     * Create a simple in-memory PSR-16 store for testing.
     */
    private function createArrayStore(): CacheInterface
    {
        return new class implements CacheInterface {
            /** @var array<string, mixed> */
            private array $data = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }

            public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
            {
                $this->data[$key] = $value;
                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->data[$key]);
                return true;
            }

            public function clear(): bool
            {
                $this->data = [];
                return true;
            }

            public function has(string $key): bool
            {
                return isset($this->data[$key]);
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                $result = [];
                foreach ($keys as $key) {
                    $result[$key] = $this->data[$key] ?? $default;
                }
                return $result;
            }

            /** @param iterable<string, mixed> $values */
            public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->data[$key] = $value;
                }
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $key) {
                    unset($this->data[$key]);
                }
                return true;
            }
        };
    }
}
