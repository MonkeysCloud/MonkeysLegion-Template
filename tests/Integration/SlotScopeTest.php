<?php

declare(strict_types=1);

namespace Tests\Integration;

use MonkeysLegion\Template\Renderer;
use MonkeysLegion\Template\Parser;
use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Loader;
use PHPUnit\Framework\TestCase;

class SlotScopeTest extends TestCase
{
    private string $tempDir;
    private Renderer $renderer;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ml_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/components', 0755, true);

        $cachePath = $this->tempDir . '/cache';
        mkdir($cachePath, 0755, true);
        $loader = new Loader($this->tempDir, $cachePath);
        $this->renderer = new Renderer(new Parser(), new Compiler(new Parser()), $loader, false, $cachePath);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $path): void
    {
        $files = glob($path . '/*');
        foreach ($files as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($path);
    }

    public function testSlotCanAccessVariablesFromParentScope(): void
    {
        // 1. Create a component that renders a slot
        $componentSource = <<<'EOT'
<div class="card">
    {{ $slots->header }}
    {{ $slot }}
</div>
EOT;
        file_put_contents($this->tempDir . '/components/card.ml.php', $componentSource);

        // 2. Create a page that uses the component and a slot
        $pageSource = <<<'EOT'
<x-card>
    @slot('header')
        <h1>Header: {{ $title }}</h1>
    @endslot
    
    <p>Body content</p>
</x-card>
EOT;
        file_put_contents($this->tempDir . '/page.ml.php', $pageSource);

        // 3. Render and expect success
        $output = $this->renderer->render('page', ['title' => 'Passed Title']);
        $this->assertStringContainsString('Header: Passed Title', $output);
    }

    public function testSlotCanAccessVariablesDefinedInTemplate(): void
    {
        // 1. Create a component that renders a slot
        $componentSource = <<<'EOT'
<div class="card">
    {{ $slots->header }}
    {{ $slot }}
</div>
EOT;
        file_put_contents($this->tempDir . '/components/card.ml.php', $componentSource);

        // 2. Create a page that defines a local variable and uses the component
        $pageSource = <<<'EOT'
@php($localTitle = 'Local Scope Title')
<x-card>
    @slot('header')
        <h1>Header: {{ $localTitle }}</h1>
    @endslot
</x-card>
EOT;
        file_put_contents($this->tempDir . '/page_local.ml.php', $pageSource);

        // 3. Render and expect success
        $output = $this->renderer->render('page_local');
        
        $this->assertStringContainsString('Header: Local Scope Title', $output);
    }
}
