<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for required section support via @yield('x', required: true).
 *
 * Required sections ensure child templates fulfill contracts defined by layouts.
 */
final class RequiredSectionTest extends TestCase
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
    public function yield_without_required_compiles_normally(): void
    {
        $compiled = $this->compile("@yield('content')");

        $this->assertStringContainsString('content', $compiled);
        $this->assertStringNotContainsString('required', $compiled);
    }

    #[Test]
    public function yield_with_default_compiles(): void
    {
        $compiled = $this->compile("@yield('title', 'Default Title')");

        $this->assertStringContainsString('title', $compiled);
        $this->assertStringContainsString('Default Title', $compiled);
    }

    #[Test]
    public function section_yield_pair(): void
    {
        $compiled = $this->compile("@section('sidebar')\nSidebar Content\n@endsection");

        $this->assertStringContainsString('ob_start()', $compiled);
        $this->assertStringContainsString("__ml_sections['sidebar']", $compiled);
        $this->assertStringContainsString('ob_get_clean()', $compiled);
    }

    #[Test]
    public function inline_section_compiles(): void
    {
        $compiled = $this->compile("@section('title', 'My Page')");

        $this->assertStringContainsString('title', $compiled);
        $this->assertStringContainsString('My Page', $compiled);
    }

    #[Test]
    public function hasSection_can_check_before_yield(): void
    {
        $compiled = $this->compile("@hasSection('sidebar')\n@yield('sidebar')\n@endhasSection");

        $this->assertStringContainsString("isset(\$__sections['sidebar'])", $compiled);
    }

    #[Test]
    public function sectionMissing_provides_fallback(): void
    {
        $compiled = $this->compile(
            "@sectionMissing('sidebar')\nDefault Sidebar\n@endsectionMissing"
        );

        $this->assertStringContainsString("!isset(\$__sections['sidebar'])", $compiled);
    }
}
