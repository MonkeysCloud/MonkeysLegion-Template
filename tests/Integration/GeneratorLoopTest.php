<?php

namespace Tests\Integration;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Loader;
use MonkeysLegion\Template\Parser;
use MonkeysLegion\Template\Renderer;
use PHPUnit\Framework\TestCase;

class GeneratorLoopTest extends TestCase
{
    private string $viewsDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->viewsDir = sys_get_temp_dir() . '/mlview_gen_tests/views';
        $this->cacheDir = sys_get_temp_dir() . '/mlview_gen_tests/cache';

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

    public function testForeachWorksWithGenerators()
    {
        file_put_contents($this->viewsDir . '/gen_test.ml.php', '
@foreach($items as $item)
    Item: {{ $item }}
@endforeach
        ');

        $loader = new Loader($this->viewsDir, $this->cacheDir);
        $parser = new Parser();
        $compiler = new Compiler($parser);
        // Disable cache to use fresh compile
        $renderer = new Renderer($parser, $compiler, $loader, false, $this->cacheDir);

        $generator = (function() {
            yield 'A';
            yield 'B';
            yield 'C';
        })();

        $output = $renderer->render('gen_test', ['items' => $generator]);

        $this->assertStringContainsString('Item: A', $output);
        $this->assertStringContainsString('Item: B', $output);
        $this->assertStringContainsString('Item: C', $output);
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
