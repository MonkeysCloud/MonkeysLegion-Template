<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Support\ScopedStyleCompiler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Svelte-inspired scoped styles.
 */
final class ScopedStyleTest extends TestCase
{
    private ScopedStyleCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new ScopedStyleCompiler();
    }

    #[Test]
    public function generates_deterministic_scope_id(): void
    {
        $id1 = $this->compiler->generateScopeId('/path/to/component.ml.php');
        $id2 = $this->compiler->generateScopeId('/path/to/component.ml.php');

        $this->assertSame($id1, $id2);
        $this->assertStringStartsWith('ml-', $id1);
        $this->assertSame(9, strlen($id1)); // ml- + 6 hex chars
    }

    #[Test]
    public function different_paths_get_different_ids(): void
    {
        $id1 = $this->compiler->generateScopeId('/path/to/card.ml.php');
        $id2 = $this->compiler->generateScopeId('/path/to/button.ml.php');

        $this->assertNotSame($id1, $id2);
    }

    #[Test]
    public function scopes_simple_selector(): void
    {
        $css = '.card { border: 1px solid #e2e8f0; }';
        $scoped = $this->compiler->scopeSelectors($css, 'ml-abc123');

        $this->assertStringContainsString('.card[data-ml-abc123]', $scoped);
        $this->assertStringContainsString('border: 1px solid #e2e8f0', $scoped);
    }

    #[Test]
    public function scopes_descendant_selector(): void
    {
        $css = '.card h3 { color: #1a202c; }';
        $scoped = $this->compiler->scopeSelectors($css, 'ml-xyz789');

        $this->assertStringContainsString('.card[data-ml-xyz789]', $scoped);
        $this->assertStringContainsString('h3', $scoped);
    }

    #[Test]
    public function scopes_multiple_selectors(): void
    {
        $css = '.card, .panel { padding: 1rem; }';
        $scoped = $this->compiler->scopeSelectors($css, 'ml-test01');

        $this->assertStringContainsString('.card[data-ml-test01]', $scoped);
        $this->assertStringContainsString('.panel[data-ml-test01]', $scoped);
    }

    #[Test]
    public function extracts_style_block_from_template(): void
    {
        $source = '<div class="card">Content</div>
<style scoped>
.card { border: 1px solid red; }
</style>';

        $result = $this->compiler->process($source, '/components/card.ml.php');

        // Style block should be removed from HTML
        $this->assertStringNotContainsString('<style', $result['html']);
        // Scope ID should be generated
        $this->assertNotNull($result['scopeId']);
        // Styles should be collected
        $this->assertTrue($this->compiler->hasStyles());
    }

    #[Test]
    public function collected_styles_are_scoped(): void
    {
        $source = '<div class="btn">Click</div>
<style scoped>
.btn { background: blue; }
</style>';

        $result = $this->compiler->process($source, '/components/btn.ml.php');

        $styles = $this->compiler->getCollectedStyles();
        $this->assertStringContainsString("[data-{$result['scopeId']}]", $styles);
        $this->assertStringContainsString('background: blue', $styles);
    }

    #[Test]
    public function adds_scope_attribute_to_root_element(): void
    {
        $source = '<div class="card">Content</div>
<style scoped>.card { color: red; }</style>';

        $result = $this->compiler->process($source, '/components/card.ml.php');

        $this->assertStringContainsString("data-{$result['scopeId']}", $result['html']);
    }

    #[Test]
    public function no_style_block_returns_null_scope(): void
    {
        $source = '<div class="plain">No styles</div>';

        $result = $this->compiler->process($source, '/components/plain.ml.php');

        $this->assertNull($result['scopeId']);
    }

    #[Test]
    public function clear_removes_collected_styles(): void
    {
        $this->compiler->process(
            '<div>X</div><style scoped>.x { color: red; }</style>',
            '/x.ml.php',
        );

        $this->assertTrue($this->compiler->hasStyles());

        $this->compiler->clear();

        $this->assertFalse($this->compiler->hasStyles());
    }
}
