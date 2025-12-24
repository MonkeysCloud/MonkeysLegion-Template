<?php

declare(strict_types=1);

namespace Tests;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Contracts\LoaderInterface;
use MonkeysLegion\Template\Parser;
use MonkeysLegion\Template\Renderer;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected Renderer $renderer;
    protected string $cacheDir;
    /** @var array<string, string> */
    protected array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = sys_get_temp_dir() . '/ml_test_cache_' . uniqid();
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }

        $loader = $this->createMock(LoaderInterface::class);
        $loader->method('getSourcePath')->willReturnCallback(function ($name) {
            // Support dot notation for directories (e.g. components.button -> components/button.php)
            // But strict file names for createView
            $filename = str_replace('.', '/', $name) . '.ml.php';
            $path = $this->cacheDir . '/' . $filename;

            if (isset($this->files[$name])) {
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0777, true);
                }
                file_put_contents($path, $this->files[$name]);
                return $path;
            }

            // Also check if keys are strict paths
            if (isset($this->files[$filename])) {
                file_put_contents($path, $this->files[$filename]);
                return $path;
            }

            throw new \RuntimeException("View [$name] not found.");
        });

        $parser = new Parser();
        $compiler = new Compiler($parser);

        $this->renderer = new Renderer(
            $parser,
            $compiler,
            $loader,
            true,
            $this->cacheDir
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup($this->cacheDir);
        parent::tearDown();
    }

    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->cleanup("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }

    /**
     * Helper to create a view file in the valid memory array
     */
    protected function createView(string $name, string $content): void
    {
        $this->files[$name] = $content;
    }

    /**
     * Helper to create a component
     */
    protected function createComponent(string $name, string $content): void
    {
        // Components usually resolved as 'components.name' or just 'name' in some paths?
        // Renderer::resolveComponent tries prefixes: components, layouts, partials
        // So if we name it 'alert', we should put it in 'components.alert'
        $this->files['components.' . $name] = $content;
    }
}
