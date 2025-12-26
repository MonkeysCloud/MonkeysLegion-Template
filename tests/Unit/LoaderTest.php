<?php

namespace Tests\Unit;

use MonkeysLegion\Template\Loader;
use PHPUnit\Framework\TestCase;

class LoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ml_loader_test_' . uniqid();
        mkdir($this->tmpDir);
        mkdir($this->tmpDir . '/views');
        mkdir($this->tmpDir . '/cache');
    }

    protected function tearDown(): void
    {
        $this->cleanup($this->tmpDir);
    }

    private function cleanup($dir)
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->cleanup("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }

    public function testResolveSinglePath()
    {
        file_put_contents($this->tmpDir . '/views/home.ml.php', 'HOME');
        $loader = new Loader($this->tmpDir . '/views', $this->tmpDir . '/cache');
        
        $path = $loader->getSourcePath('home');
        $this->assertStringContainsString('views/home.ml.php', $path);
    }

    public function testResolveMultiplePathsFallback()
    {
        // Structure:
        // /views/default/page.ml.php
        // /views/theme/page.ml.php
        
        $defaultDir = $this->tmpDir . '/views/default';
        $themeDir = $this->tmpDir . '/views/theme';
        mkdir($defaultDir, 0777, true);
        mkdir($themeDir, 0777, true);

        file_put_contents($defaultDir . '/page.ml.php', 'DEFAULT');
        file_put_contents($themeDir . '/page.ml.php', 'THEME');

        $loader = new Loader([$themeDir, $defaultDir], $this->tmpDir . '/cache');
        
        // Should find THEME first
        $path = $loader->getSourcePath('page');
        $this->assertStringContainsString('views/theme/page.ml.php', $path);
        
        // Remove theme file, should fallback
        unlink($themeDir . '/page.ml.php');
        $path = $loader->getSourcePath('page');
        $this->assertStringContainsString('views/default/page.ml.php', $path);
    }

    public function testNamespaces()
    {
        $vendorDir = $this->tmpDir . '/vendor/pkg/views';
        mkdir($vendorDir, 0777, true);
        file_put_contents($vendorDir . '/alert.ml.php', 'ALERT');

        $loader = new Loader($this->tmpDir . '/views', $this->tmpDir . '/cache');
        $loader->addNamespace('pkg', $vendorDir);

        $path = $loader->getSourcePath('pkg::alert');
        $this->assertStringContainsString('vendor/pkg/views/alert.ml.php', $path);
    }

    public function testNamespaceOverride()
    {
        // vendor has alert
        $vendorDir = $this->tmpDir . '/vendor/pkg/views';
        mkdir($vendorDir, 0777, true);
        file_put_contents($vendorDir . '/alert.ml.php', 'ORIGINAL');

        // main view path has override: vendor/pkg/alert.ml.php
        // Note: The logic implemented expects `vendor/{namespace}/{view}` in main paths.
        $overrideDir = $this->tmpDir . '/views/vendor/pkg';
        mkdir($overrideDir, 0777, true);
        file_put_contents($overrideDir . '/alert.ml.php', 'OVERRIDE');

        $loader = new Loader($this->tmpDir . '/views', $this->tmpDir . '/cache');
        $loader->addNamespace('pkg', $vendorDir);

        $path = $loader->getSourcePath('pkg::alert');
        $this->assertStringContainsString('views/vendor/pkg/alert.ml.php', $path);
    }
}
