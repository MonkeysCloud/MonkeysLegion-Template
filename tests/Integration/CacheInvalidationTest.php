<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for template cache invalidation.
 *
 * Verifies that the Renderer correctly invalidates cached compiled templates
 * when source files change, and that cache-enabled/disabled modes work.
 */
class CacheInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer->clearCache();
    }

    #[Test]
    public function renders_and_caches_template(): void
    {
        $this->createView('cached_view', '<p>Original</p>');

        $output1 = $this->renderer->render('cached_view');
        $this->assertStringContainsString('Original', $output1);

        // Second render should use cache
        $output2 = $this->renderer->render('cached_view');
        $this->assertSame($output1, $output2);
    }

    #[Test]
    public function detects_source_change_and_recompiles(): void
    {
        $this->createView('changeable', '<p>Version 1</p>');

        $output1 = $this->renderer->render('changeable');
        $this->assertStringContainsString('Version 1', $output1);

        // Wait briefly to ensure mtime changes
        sleep(1);

        // Update the view source
        $this->createView('changeable', '<p>Version 2</p>');

        $output2 = $this->renderer->render('changeable');
        $this->assertStringContainsString('Version 2', $output2);
    }

    #[Test]
    public function clear_cache_removes_all_compiled(): void
    {
        $this->createView('cache_test_a', '<p>A</p>');
        $this->createView('cache_test_b', '<p>B</p>');

        $this->renderer->render('cache_test_a');
        $this->renderer->render('cache_test_b');

        $this->renderer->clearCache();

        // Files should be gone — re-rendering should work (recompiles)
        $output = $this->renderer->render('cache_test_a');
        $this->assertStringContainsString('A', $output);
    }

    #[Test]
    public function cache_disabled_always_recompiles(): void
    {
        // The test case sets cacheEnabled=false in setUp
        $this->createView('no_cache', '<p>{{ $msg }}</p>');

        $output = $this->renderer->render('no_cache', ['msg' => 'Hello']);
        $this->assertStringContainsString('Hello', $output);
    }

    #[Test]
    public function compiled_cache_contains_valid_php(): void
    {
        $this->createView('valid_php', '<p>{{ $name }}</p>');

        $this->renderer->render('valid_php', ['name' => 'Test']);

        // Check that cache dir has compiled files (files with md5 hash in name)
        $cacheDir = $this->getCacheDir();
        $files = glob($cacheDir . '/*_*.php') ?: [];
        $this->assertNotEmpty($files, 'Should have compiled cache files');

        // Compiled files should contain <?php
        $foundValid = false;
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content !== false && str_contains($content, '<?php')) {
                $foundValid = true;
                break;
            }
        }
        $this->assertTrue($foundValid, 'Should have at least one compiled PHP file');
    }

    #[Test]
    public function echo_expressions_are_compiled_in_cache(): void
    {
        $this->createView('echo_cache', '{{ $var }}');

        $this->renderer->render('echo_cache', ['var' => 'value']);

        $cacheDir = $this->getCacheDir();
        $files = glob($cacheDir . '/*.php') ?: [];
        $this->assertNotEmpty($files);

        // At least one file should contain the Escaper call
        $found = false;
        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            if (str_contains($content, 'Escaper::html')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Compiled cache should contain Escaper::html');
    }

    #[Test]
    public function multiple_renders_same_data(): void
    {
        $this->createView('stable', '<h1>{{ $title }}</h1>');

        $output1 = $this->renderer->render('stable', ['title' => 'Stable']);
        $output2 = $this->renderer->render('stable', ['title' => 'Stable']);
        $output3 = $this->renderer->render('stable', ['title' => 'Stable']);

        $this->assertSame($output1, $output2);
        $this->assertSame($output2, $output3);
    }

    #[Test]
    public function different_data_same_template(): void
    {
        $this->createView('dynamic', '<p>{{ $value }}</p>');

        $output1 = $this->renderer->render('dynamic', ['value' => 'A']);
        $output2 = $this->renderer->render('dynamic', ['value' => 'B']);

        $this->assertStringContainsString('A', $output1);
        $this->assertStringContainsString('B', $output2);
        $this->assertNotSame($output1, $output2);
    }

    private function getCacheDir(): string
    {
        return $this->cacheDir;
    }
}
