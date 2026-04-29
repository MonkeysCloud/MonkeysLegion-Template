<?php

namespace Tests\Integration;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Loader;
use MonkeysLegion\Template\Parser;
use MonkeysLegion\Template\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests that @push/@prepend blocks placed OUTSIDE @section blocks
 * in a child template are correctly preserved when using @extends.
 *
 * This is the Blade-standard pattern:
 *   @extends('layout')
 *   @section('content') ... @endsection
 *   @push('scripts') ... @endpush       ← outside @section
 */
class PushOutsideSectionTest extends TestCase
{
    private string $viewsDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->viewsDir = sys_get_temp_dir() . '/mlview_push_outside_tests/views';
        $this->cacheDir = sys_get_temp_dir() . '/mlview_push_outside_tests/cache';

        if (!is_dir($this->viewsDir)) {
            mkdir($this->viewsDir, 0777, true);
        }
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory(dirname($this->viewsDir));
    }

    /**
     * @push outside @section should be rendered in the parent layout's @stack.
     */
    public function testPushOutsideSectionRendersInStack(): void
    {
        // Parent layout with @stack('scripts')
        file_put_contents($this->viewsDir . '/layout.ml.php', <<<'BLADE'
<html>
<head>@stack('head')</head>
<body>
    @yield('content')
    @stack('scripts')
</body>
</html>
BLADE);

        // Child: @push('scripts') is OUTSIDE @section('content')
        file_put_contents($this->viewsDir . '/child.ml.php', <<<'BLADE'
@extends('layout')

@section('content')
    <h1>Hello World</h1>
@endsection

@push('scripts')
<script>console.log("login handler");</script>
@endpush

@push('head')
<link rel="stylesheet" href="/custom.css">
@endpush
BLADE);

        $loader = new Loader($this->viewsDir, $this->cacheDir);
        $parser = new Parser();
        $compiler = new Compiler($parser);
        $renderer = new Renderer($parser, $compiler, $loader, false, $this->cacheDir);

        $output = $renderer->render('child');

        // The @push('scripts') content should appear where @stack('scripts') is
        $this->assertStringContainsString('console.log("login handler")', $output);
        // The @push('head') content should appear where @stack('head') is
        $this->assertStringContainsString('/custom.css', $output);
        // Content section should still render
        $this->assertStringContainsString('<h1>Hello World</h1>', $output);
        // No raw stack placeholders should remain
        $this->assertStringNotContainsString('__ML_STACK_', $output);
    }

    /**
     * @prepend outside @section should also work.
     */
    public function testPrependOutsideSectionRendersInStack(): void
    {
        file_put_contents($this->viewsDir . '/layout2.ml.php', <<<'BLADE'
<html>
<body>
    @yield('content')
    @stack('scripts')
</body>
</html>
BLADE);

        file_put_contents($this->viewsDir . '/child2.ml.php', <<<'BLADE'
@extends('layout2')

@section('content')
    <p>Page body</p>
@endsection

@prepend('scripts')
<script>console.log("prepended");</script>
@endprepend
BLADE);

        $loader = new Loader($this->viewsDir, $this->cacheDir);
        $parser = new Parser();
        $compiler = new Compiler($parser);
        $renderer = new Renderer($parser, $compiler, $loader, false, $this->cacheDir);

        $output = $renderer->render('child2');

        $this->assertStringContainsString('console.log("prepended")', $output);
        $this->assertStringContainsString('<p>Page body</p>', $output);
    }

    /**
     * Multiple @push blocks to the same stack from outside @section.
     */
    public function testMultiplePushBlocksOutsideSection(): void
    {
        file_put_contents($this->viewsDir . '/layout3.ml.php', <<<'BLADE'
<html>
<body>
    @yield('content')
    @stack('scripts')
</body>
</html>
BLADE);

        file_put_contents($this->viewsDir . '/child3.ml.php', <<<'BLADE'
@extends('layout3')

@section('content')
    <p>Content</p>
@endsection

@push('scripts')
<script>console.log("first");</script>
@endpush

@push('scripts')
<script>console.log("second");</script>
@endpush
BLADE);

        $loader = new Loader($this->viewsDir, $this->cacheDir);
        $parser = new Parser();
        $compiler = new Compiler($parser);
        $renderer = new Renderer($parser, $compiler, $loader, false, $this->cacheDir);

        $output = $renderer->render('child3');

        $this->assertStringContainsString('console.log("first")', $output);
        $this->assertStringContainsString('console.log("second")', $output);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $files = glob($path . '/*') ?: [];
        foreach ($files as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($path);
    }
}
