<?php

declare(strict_types=1);

namespace Tests\Integration;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Contracts\LoaderInterface;
use MonkeysLegion\Template\Parser;
use MonkeysLegion\Template\Renderer;
use PHPUnit\Framework\TestCase;

class RendererTest extends TestCase
{
    private Renderer $renderer;
    private string $cacheDir;
    /** @var array<string, string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ml_test_cache_' . uniqid();
        mkdir($this->cacheDir);

        $loader = $this->createMock(LoaderInterface::class);
        $loader->method('getSourcePath')->willReturnCallback(function ($name) {
            $path = $this->cacheDir . '/' . str_replace('.', '/', $name) . '.ml.php';
            if (isset($this->files[$name])) {
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0777, true);
                }
                file_put_contents($path, $this->files[$name]);
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
    }

    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->cleanup("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }

    public function testItRendersSimpleTemplate(): void
    {
        $this->files['home'] = '<h1>Hello {{ $name }}</h1>';

        $output = $this->renderer->render('home', ['name' => 'World']);

        $this->assertEquals('<h1>Hello World</h1>', $output);
    }

    public function testItRendersWithLayout(): void
    {
        $this->files['layouts.app'] = '<html><body>@yield("content")</body></html>';
        $this->files['dashboard'] = '@extends("layouts.app")@section("content")<p>Dashboard</p>@endsection';

        $output = $this->renderer->render('dashboard');

        $this->assertEquals('<html><body><p>Dashboard</p></body></html>', $output);
    }

    public function testItRendersComponent(): void
    {
        // Define component - note the directory structure implied by dots
        $this->files['components.button'] = '<button class="btn">{{ $slot }}</button>';

        $this->files['page'] = '<x-button>Click Me</x-button>';

        $output = $this->renderer->render('page');

        // Allow for some whitespace differences
        $this->assertStringContainsString('<button class="btn">Click Me</button>', trim($output));
    }

    public function testItRendersComponentWithProps(): void
    {
        $this->files['components.alert'] = '@props(["type" => "info"])<div class="alert {{ $type }}">{{ $slot }}</div>';

        $this->files['page'] = '<x-alert type="error">Something failed</x-alert>';

        $output = $this->renderer->render('page');

        $this->assertStringContainsString('<div class="alert error">Something failed</div>', trim($output));
    }
}
