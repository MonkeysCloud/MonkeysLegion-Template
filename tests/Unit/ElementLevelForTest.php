<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for HEEx-style :for element-level directive.
 */
final class ElementLevelForTest extends TestCase
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
    public function for_on_opening_closing_tag(): void
    {
        $compiled = $this->compile('<li :for="$items as $item" class="item">{{ $item }}</li>');

        $this->assertStringContainsString('foreach(', $compiled);
        $this->assertStringContainsString('endforeach;', $compiled);
        $this->assertStringContainsString('<li class="item">', $compiled);
        $this->assertStringNotContainsString(':for=', $compiled);
    }

    #[Test]
    public function for_on_self_closing_tag(): void
    {
        $compiled = $this->compile('<option :for="$options as $opt" value="test" />');

        $this->assertStringContainsString('foreach(', $compiled);
        $this->assertStringContainsString('endforeach;', $compiled);
        $this->assertStringContainsString('value="test"', $compiled);
    }

    #[Test]
    public function for_includes_loop_variable(): void
    {
        $compiled = $this->compile('<li :for="$items as $item">{{ $item }}</li>');

        $this->assertStringContainsString('addLoop(', $compiled);
        $this->assertStringContainsString('getLastLoop()', $compiled);
        $this->assertStringContainsString('popLoop()', $compiled);
    }

    #[Test]
    public function for_with_key_value(): void
    {
        $compiled = $this->compile('<dd :for="$data as $key => $value">{{ $key }}: {{ $value }}</dd>');

        $this->assertStringContainsString('$key => $value', $compiled);
        $this->assertStringContainsString('foreach(', $compiled);
    }

    #[Test]
    public function for_preserves_other_attributes(): void
    {
        $compiled = $this->compile('<tr :for="$rows as $row" class="row" id="table-row">{{ $row }}</tr>');

        $this->assertStringContainsString('class="row"', $compiled);
        $this->assertStringContainsString('id="table-row"', $compiled);
        $this->assertStringNotContainsString(':for=', $compiled);
    }
}
