<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for @autoescape / @endautoescape Jinja2-style context escaping.
 */
final class AutoescapeTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler(new Parser());
    }

    private function compile(string $source): string
    {
        return $this->compiler->compile($source, '/tmp/test.ml.php');
    }

    #[Test]
    public function autoescape_sets_context_variable(): void
    {
        $compiled = $this->compile("@autoescape('js')\nContent\n@endautoescape");

        $this->assertStringContainsString("\$__escapeContext = 'js'", $compiled);
    }

    #[Test]
    public function autoescape_saves_previous_context(): void
    {
        $compiled = $this->compile("@autoescape('css')\nStyled\n@endautoescape");

        $this->assertStringContainsString('$__previousEscapeContext', $compiled);
    }

    #[Test]
    public function autoescape_restores_previous_context(): void
    {
        $compiled = $this->compile("@autoescape('url')\nLink\n@endautoescape");

        // endautoescape should restore the previous context
        $this->assertStringContainsString("\$__escapeContext = \$__previousEscapeContext", $compiled);
    }

    #[Test]
    public function autoescape_html_context(): void
    {
        $compiled = $this->compile("@autoescape('html')\n{{ \$var }}\n@endautoescape");

        $this->assertStringContainsString("\$__escapeContext = 'html'", $compiled);
    }

    #[Test]
    public function autoescape_none_context(): void
    {
        $compiled = $this->compile("@autoescape('none')\n{{ \$var }}\n@endautoescape");

        $this->assertStringContainsString("\$__escapeContext = 'none'", $compiled);
    }

    #[Test]
    public function nested_autoescape_blocks(): void
    {
        $compiled = $this->compile(
            "@autoescape('js')\n@autoescape('css')\nInner\n@endautoescape\n@endautoescape"
        );

        // Should have 4 context assignments: 2 sets from @autoescape + 2 restores from @endautoescape
        $this->assertEquals(4, substr_count($compiled, '$__escapeContext ='));
        // And 2 saves of previous context
        $this->assertEquals(2, substr_count($compiled, '$__previousEscapeContext ='));
    }
}
