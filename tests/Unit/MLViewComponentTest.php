<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Loader;
use MonkeysLegion\Template\MLView;
use MonkeysLegion\Template\Parser;
use MonkeysLegion\Template\Renderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MLView::component() function component registration.
 */
final class MLViewComponentTest extends TestCase
{
    private string $tempDir;
    private string $cacheDir;
    private MLView $view;
    private Renderer $renderer;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ml_cmp_test_' . uniqid();
        $this->cacheDir = sys_get_temp_dir() . '/ml_cmp_cache_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->cacheDir, 0755, true);

        $parser = new Parser();
        $compiler = new Compiler($parser);
        $loader = new Loader($this->tempDir, $this->cacheDir);
        $this->renderer = new Renderer($parser, $compiler, $loader, false, $this->cacheDir);
        $this->view = new MLView($loader, $compiler, $this->renderer, $this->cacheDir);
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
    public function component_registers_function_component(): void
    {
        $this->view->component('badge', fn(string $text) => "<span class=\"badge\">{$text}</span>");

        $this->assertTrue($this->renderer->hasFunctionComponent('badge'));
    }

    #[Test]
    public function component_retrieves_callable(): void
    {
        $fn = fn(string $text) => "<span>{$text}</span>";
        $this->view->component('tag', $fn);

        $retrieved = $this->renderer->getFunctionComponent('tag');
        $this->assertNotNull($retrieved);
    }

    #[Test]
    public function unregistered_component_returns_null(): void
    {
        $this->assertNull($this->renderer->getFunctionComponent('nonexistent'));
        $this->assertFalse($this->renderer->hasFunctionComponent('nonexistent'));
    }

    #[Test]
    public function component_callable_executes(): void
    {
        $this->view->component('badge', fn(string $text, string $color = 'blue') =>
            "<span class=\"badge bg-{$color}\">" . htmlspecialchars($text) . "</span>"
        );

        $fn = $this->renderer->getFunctionComponent('badge');
        $this->assertNotNull($fn);

        /** @var callable $fn */
        $output = $fn('Active', 'green');
        $this->assertSame('<span class="badge bg-green">Active</span>', $output);
    }
}
