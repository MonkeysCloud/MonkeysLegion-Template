<?php

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use MonkeysLegion\Template\Renderer;
use MonkeysLegion\Template\Contracts\LoaderInterface;
use MonkeysLegion\Template\Exceptions\ViewException;
use PHPUnit\Framework\TestCase;

class SourceMapTest extends TestCase
{
    private string $cacheDir;
    private string $viewPath;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ml_tests_' . uniqid();
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        $this->viewPath = $this->cacheDir . '/test_view.blade.php';
    }

    protected function tearDown(): void
    {
        // Cleanup
        if (file_exists($this->viewPath)) {
            unlink($this->viewPath);
        }
        $files = glob($this->cacheDir . '/*.php');
        if ($files) {
            foreach ($files as $f) unlink($f);
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
    }

    public function test_it_maps_exceptions_to_original_view_file()
    {
        // 1. Create a view that throws an exception
        // We use @php block to throw explicitly
        $content = <<<'BLADE'
Line 1
Line 2
Line 3
@php
    throw new \Exception("Something went wrong");
@endphp
Line 7
BLADE;
        file_put_contents($this->viewPath, $content);

        // 2. Setup Renderer
        $parser = new Parser();
        $compiler = new Compiler($parser);
        
        // Mock Loader
        $loader = new class($this->viewPath) implements LoaderInterface {
            private $path;
            public function __construct($path) { $this->path = $path; }
            public function getSourcePath(string $name): string {
                return $this->path;
            }
        };

        // Cache enabled so we test compiling + execution from file
        $renderer = new Renderer($parser, $compiler, $loader, true, $this->cacheDir);

        try {
            $renderer->render('test_view');
            $this->fail("ViewException was not thrown");
        } catch (ViewException $e) {
            // 3. Assertion
            $this->assertEquals($this->viewPath, $e->getFile());
            $this->assertStringContainsString("Something went wrong", $e->getMessage());
            $this->assertStringContainsString("test_view.blade.php", $e->getMessage());

            // Line 5 is where execution happens for the throw
            // Line 1
            // Line 2
            // Line 3
            // @php (Line 4)
            //    throw ... (Line 5)
            
            // Allow +/- 1 line drift
            $this->assertTrue(
                abs($e->getLine() - 5) <= 1,
                "Expected error around line 5, got {$e->getLine()}"
            );
        }
    }

    public function test_it_maps_syntax_errors_in_compiled_code()
    {
        // 1. Create a view with invalid PHP syntax generated via blade?
        // Hard to generate invalid PHP via valid Blade unless valid blade -> invalid php.
        // e.g. {{ $var; }} (Blade usually treats this as echo($var;)) which is syntax error.
        
        $content = <<<'BLADE'
Line 1
Line 2
{{ $foo; }}
Line 4
BLADE;
        file_put_contents($this->viewPath, $content);

        $parser = new Parser();
        $compiler = new Compiler($parser);
        
        $loader = new class($this->viewPath) implements LoaderInterface {
            private $path;
            public function __construct($path) { $this->path = $path; }
            public function getSourcePath(string $name): string {
                return $this->path;
            }
        };

        $renderer = new Renderer($parser, $compiler, $loader, true, $this->cacheDir);

        try {
            // This might throw a ParseError which is a Throwable
            $renderer->render('test_view');
            $this->fail("Exception was not thrown for syntax error");
        } catch (ViewException $e) {
            $this->assertEquals($this->viewPath, $e->getFile());
            // Line 3 is {{ $foo; }}
            $this->assertTrue(abs($e->getLine() - 3) <= 1, "Expected line 3, got " . $e->getLine());
        } catch (\Throwable $t) {
             // Sometimes parse errors might not be mapped if they happen at compile time?
             // But Compiler just does str_replace. The error happens at 'include' time.
             // So it should be caught by Renderer's try-catch around include.
             
             // However, ParseError fatal errors used to be uncatchable in PHP 5, but usually catchable in 7+.
             // Let's see.
             
             // If handleViewException catches it, it should wrap it.
             $this->assertInstanceOf(ViewException::class, $t, "Should have been wrapped in ViewException, got " . get_class($t));
        }
    }
}
