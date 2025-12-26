<?php

namespace Tests\Integration;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Loader;
use MonkeysLegion\Template\Parser;
use MonkeysLegion\Template\Renderer;
use PHPUnit\Framework\TestCase;

class StackNoCacheTest extends TestCase
{
    private string $viewsDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->viewsDir = sys_get_temp_dir() . '/mlview_stack_tests/views';
        $this->cacheDir = sys_get_temp_dir() . '/mlview_stack_tests/cache';

        if (!is_dir($this->viewsDir)) {
            mkdir($this->viewsDir, 0777, true);
        }
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->viewsDir);
        $this->removeDirectory($this->cacheDir);
    }

    public function testStackWorksWithoutCache()
    {
        // Create a layout with a stack
        file_put_contents($this->viewsDir . '/layout.ml.php', '
<html>
<head>
    @stack(\'styles\')
</head>
<body>
    @yield(\'content\')
</body>
</html>
        ');

        // Create a page that extends layout and pushes to stack
        file_put_contents($this->viewsDir . '/page.ml.php', '
@extends(\'layout\')

@section(\'content\')
    <h1>Page Content</h1>
    @push(\'styles\')
        <style>body { background: red; }</style>
    @endpush
@endsection
        ');

        $loader = new Loader($this->viewsDir, $this->cacheDir);
        $parser = new Parser();
        $compiler = new Compiler($parser);
        
        // Disable cache
        $renderer = new Renderer($parser, $compiler, $loader, false, $this->cacheDir);

        $output = $renderer->render('page');

        $this->assertStringContainsString('<style>body { background: red; }</style>', $output);
        $this->assertStringNotContainsString('<!-- __ML_STACK_styles__ -->', $output);
    }

    private function removeDirectory($path)
    {
        if (!is_dir($path)) {
            return;
        }
        $files = glob($path . '/*');
        foreach ($files as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($path);
    }
}
