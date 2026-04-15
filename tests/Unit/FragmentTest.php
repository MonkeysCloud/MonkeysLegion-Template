<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Dedicated tests for @fragment / @endfragment (HTMX partial rendering).
 */
final class FragmentTest extends TestCase
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
    public function fragment_compiles_with_ob_start(): void
    {
        $compiled = $this->compile("@fragment('user-list')\n<ul></ul>\n@endfragment");

        $this->assertStringContainsString("__fragmentName = 'user-list'", $compiled);
        $this->assertStringContainsString('ob_start()', $compiled);
    }

    #[Test]
    public function fragment_checks_htmx_request(): void
    {
        $compiled = $this->compile("@fragment('sidebar')\nContent\n@endfragment");

        $this->assertStringContainsString('isHtmxRequest()', $compiled);
        $this->assertStringContainsString('HX-Target', $compiled);
        $this->assertStringContainsString('HX-Fragment', $compiled);
    }

    #[Test]
    public function fragment_uses_ob_get_clean(): void
    {
        $compiled = $this->compile("@fragment('nav')\n<nav></nav>\n@endfragment");

        $this->assertStringContainsString('ob_get_clean()', $compiled);
    }

    #[Test]
    public function fragment_echoes_content_for_non_htmx(): void
    {
        $compiled = $this->compile("@fragment('main')\nBody\n@endfragment");

        // Should echo content even when not HTMX
        $this->assertStringContainsString('echo $__fragmentContent', $compiled);
    }

    #[Test]
    public function multiple_fragments_compile_independently(): void
    {
        $compiled = $this->compile(
            "@fragment('header')\nHeader\n@endfragment\n@fragment('footer')\nFooter\n@endfragment"
        );

        $this->assertEquals(2, substr_count($compiled, 'ob_start()'));
        $this->assertEquals(2, substr_count($compiled, 'ob_get_clean()'));
    }
}
