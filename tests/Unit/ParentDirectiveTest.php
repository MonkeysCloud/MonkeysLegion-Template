<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Dedicated tests for @parent directive.
 */
final class ParentDirectiveTest extends TestCase
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
    public function parent_compiles_to_placeholder(): void
    {
        $compiled = $this->compile("@parent");

        $this->assertStringContainsString('@@parent_placeholder@@', $compiled);
    }

    #[Test]
    public function parent_inside_section(): void
    {
        $compiled = $this->compile("@section('sidebar')\n    @parent\n    <p>Extra content</p>\n@endsection");

        $this->assertStringContainsString('@@parent_placeholder@@', $compiled);
    }

    #[Test]
    public function parent_preserves_surrounding_content(): void
    {
        $compiled = $this->compile("Before @parent After");

        $this->assertStringContainsString('Before', $compiled);
        $this->assertStringContainsString('@@parent_placeholder@@', $compiled);
        $this->assertStringContainsString('After', $compiled);
    }
}
