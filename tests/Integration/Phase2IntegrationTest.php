<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

/**
 * Integration tests for Phase 2 directives — full render cycle.
 */
class Phase2IntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer->clearCache();
    }

    public function testForelseWithItems(): void
    {
        $this->createView('forelse_items', '
            @forelse($items as $item)
                Item: {{ $item }}
            @empty
                No items found
            @endforelse
        ');

        $output = $this->renderer->render('forelse_items', ['items' => ['A', 'B']]);

        $this->assertStringContainsString('Item: A', $output);
        $this->assertStringContainsString('Item: B', $output);
        $this->assertStringNotContainsString('No items found', $output);
    }

    public function testForelseEmpty(): void
    {
        $this->createView('forelse_empty', '
            @forelse($items as $item)
                Item: {{ $item }}
            @empty
                No items found
            @endforelse
        ');

        $output = $this->renderer->render('forelse_empty', ['items' => []]);

        $this->assertStringContainsString('No items found', $output);
        $this->assertStringNotContainsString('Item:', $output);
    }

    public function testPersistDirective(): void
    {
        $this->createView('persist_test', '
            @persist(\'sidebar\')
                <nav>Navigation</nav>
            @endpersist
        ');

        $output = $this->renderer->render('persist_test');

        $this->assertStringContainsString('id="persist-sidebar"', $output);
        $this->assertStringContainsString('data-persist', $output);
        $this->assertStringContainsString('<nav>Navigation</nav>', $output);
    }

    public function testModelDirective(): void
    {
        $this->createView('model_test', '
            @model(App\Entity\User)
            <h1>User Profile</h1>
        ');

        $output = $this->renderer->render('model_test');

        // @model produces a PHP comment, not visible output
        $this->assertStringContainsString('User Profile', $output);
    }

    public function testAutoescapeDirective(): void
    {
        $this->createView('autoescape_test', '
            @autoescape(\'js\')
                <script>var name = "test";</script>
            @endautoescape
        ');

        $output = $this->renderer->render('autoescape_test');

        $this->assertStringContainsString('<script>', $output);
    }

    public function testRequiredDirective(): void
    {
        $this->createView('required_test', '
            <input type="text" @required($isRequired)>
        ');

        $outputRequired = $this->renderer->render('required_test', ['isRequired' => true]);
        $this->assertStringContainsString('required', $outputRequired);

        $outputNotRequired = $this->renderer->render('required_test', ['isRequired' => false]);
        $this->assertStringNotContainsString('required', $outputNotRequired);
    }

    public function testTeleportDirective(): void
    {
        $this->createView('teleport_test', '
            @teleport(\'#modals\')
                <div class="modal">Modal Content</div>
            @endteleport
        ');

        $output = $this->renderer->render('teleport_test');

        $this->assertStringContainsString('<!-- teleport -->', $output);
        $this->assertStringContainsString('Modal Content', $output);
        $this->assertStringContainsString('<!-- /teleport -->', $output);
    }

    public function testHasSectionDirective(): void
    {
        $this->createView('layouts.has_section', '
            @hasSection(\'sidebar\')
                Sidebar is defined
            @endhasSection
            @sectionMissing(\'sidebar\')
                No sidebar
            @endsectionMissing
            @yield(\'content\')
        ');

        $this->createView('page_no_sidebar', '
            @extends(\'layouts.has_section\')
            @section(\'content\')
                Main Content
            @endsection
        ');

        $output = $this->renderer->render('page_no_sidebar');

        // Note: $__sections is set during layout processing
        $this->assertStringContainsString('Main Content', $output);
    }

    public function testProductionDirective(): void
    {
        $this->createView('prod_test', '
            Content
            @production
                PRODUCTION ONLY
            @endproduction
        ');

        $output = $this->renderer->render('prod_test');

        // app_env() function doesn't exist in test, so this block is skipped
        $this->assertStringContainsString('Content', $output);
        $this->assertStringNotContainsString('PRODUCTION ONLY', $output);
    }

    public function testIncludeIfDirective(): void
    {
        $this->createView('includeif_test', '
            Before
            @includeIf(\'nonexistent.view\')
            After
        ');

        $output = $this->renderer->render('includeif_test');

        // Should not throw, just silently skip
        $this->assertStringContainsString('Before', $output);
        $this->assertStringContainsString('After', $output);
    }

    public function testUseDirective(): void
    {
        $this->createView('use_test', '
            @use(\'DateTimeImmutable\')
            Today
        ');

        // This won't error since we just test rendering
        $output = $this->renderer->render('use_test');
        $this->assertStringContainsString('Today', $output);
    }

    public function testForelsePreservesStandaloneEmpty(): void
    {
        // Ensure @empty($var) standalone still works alongside @forelse
        $this->createView('both_empty', '
            @forelse($items as $item)
                {{ $item }}
            @empty
                No items
            @endforelse

            @empty($list)
                List empty
            @endempty
        ');

        $output = $this->renderer->render('both_empty', [
            'items' => [],
            'list'  => [],
        ]);

        $this->assertStringContainsString('No items', $output);
        $this->assertStringContainsString('List empty', $output);
    }
}
