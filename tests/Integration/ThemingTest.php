<?php

namespace Tests\Integration;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Loader;
use MonkeysLegion\Template\MLView;
use MonkeysLegion\Template\Parser;
use MonkeysLegion\Template\Renderer;
use PHPUnit\Framework\TestCase;

class ThemingTest extends TestCase
{
    private string $tmpDir;
    private MLView $view;
    private Loader $loader;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ml_theme_test_' . uniqid();
        mkdir($this->tmpDir);
        mkdir($this->tmpDir . '/views');
        mkdir($this->tmpDir . '/themes');
        mkdir($this->tmpDir . '/custom'); // For namespace
        mkdir($this->tmpDir . '/cache');

        $this->loader = new Loader($this->tmpDir . '/views', $this->tmpDir . '/cache');
        $parser = new Parser();
        $compiler = new Compiler($parser);
        $renderer = new Renderer(
            $parser,
            $compiler,
            $this->loader,
            true, // cache enabled
            $this->tmpDir . '/cache',
            $compiler->getRegistry()
        );

        $this->view = new MLView(
            $this->loader,
            $compiler,
            $renderer,
            $this->tmpDir . '/cache'
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup($this->tmpDir);
    }

    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->cleanup("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }

    public function testSetThemePrecedence(): void
    {
        // Default view
        file_put_contents($this->tmpDir . '/views/home.ml.php', 'DEFAULT HOME');
        
        // Render check
        $this->assertEquals('DEFAULT HOME', $this->view->render('home'));
        
        // Create theme view
        $themeName = 'dark';
        $themeDir = $this->tmpDir . '/themes/' . $themeName;
        mkdir($themeDir, 0777, true);
        file_put_contents($themeDir . '/home.ml.php', 'DARK HOME');
        
        // Set theme
        $this->view->setTheme($themeName, $this->tmpDir . '/themes');
        
        // Render check - should be overridden
        $this->assertEquals('DARK HOME', $this->view->render('home'));
    }

    public function testAddViewPathFallback(): void
    {
        file_put_contents($this->tmpDir . '/views/only_default.ml.php', 'ONLY DEFAULT');
        
        $extraPath = $this->tmpDir . '/themes/extra';
        mkdir($extraPath, 0777, true);
        file_put_contents($extraPath . '/only_extra.ml.php', 'ONLY EXTRA');
        
        $this->view->addViewPath($extraPath);
        
        $this->assertEquals('ONLY DEFAULT', $this->view->render('only_default'));
        $this->assertEquals('ONLY EXTRA', $this->view->render('only_extra'));
    }

    public function testNamespaceUsage(): void
    {
        file_put_contents($this->tmpDir . '/custom/widget.ml.php', 'CUSTOM WIDGET');
        
        $this->view->addNamespace('custom', $this->tmpDir . '/custom');
        
        $this->assertEquals('CUSTOM WIDGET', $this->view->render('custom::widget'));
    }

    public function testNamespaceThemeOverride(): void
    {
        // Packaged view
        file_put_contents($this->tmpDir . '/custom/card.ml.php', 'ORIGINAL CARD');
        $this->view->addNamespace('ui', $this->tmpDir . '/custom'); // namespace 'ui'
        
        // Theme override: themes/dark/vendor/ui/card.ml.php
        $themeName = 'dark';
        $themeDir = $this->tmpDir . '/themes/' . $themeName;
        // The loader looks for overrides in ALL paths.
        // If we setTheme, the theme path is prepended.
        // So: {themePath}/vendor/{namespace}/{view}
        
        $overrideDir = $themeDir . '/vendor/ui';
        mkdir($overrideDir, 0777, true);
        file_put_contents($overrideDir . '/card.ml.php', 'THEMED CARD');
        
        $this->view->setTheme($themeName, $this->tmpDir . '/themes');
        
        // Should find themed card
        $this->assertEquals('THEMED CARD', $this->view->render('ui::card'));
    }
}
