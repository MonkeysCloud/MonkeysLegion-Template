<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for @options([...]) template-level configuration (Svelte-inspired).
 */
final class OptionsDirectiveTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler(new Parser());
    }

    private function compile(string $source): string
    {
        return $this->compiler->compile($source, 'test.ml.php');
    }

    #[Test]
    public function options_directive_compiles(): void
    {
        $compiled = $this->compile("@options(['strict' => true])");

        $this->assertStringContainsString('$__templateOptions', $compiled);
        $this->assertStringContainsString("'strict' => true", $compiled);
    }

    #[Test]
    public function options_with_multiple_keys(): void
    {
        $compiled = $this->compile("@options(['strict' => true, 'autoescape' => 'html'])");

        $this->assertStringContainsString("'strict' => true", $compiled);
        $this->assertStringContainsString("'autoescape' => 'html'", $compiled);
    }

    #[Test]
    public function options_with_layout_sugar(): void
    {
        $compiled = $this->compile("@options(['layout' => 'layouts.app'])");

        $this->assertStringContainsString("'layout' => 'layouts.app'", $compiled);
    }

    #[Test]
    public function options_does_not_affect_content(): void
    {
        $compiled = $this->compile("@options(['strict' => true])\n<h1>Hello</h1>");

        $this->assertStringContainsString('<h1>Hello</h1>', $compiled);
    }

    #[Test]
    public function no_options_directive_means_no_template_options(): void
    {
        $compiled = $this->compile('<p>Simple template</p>');

        $this->assertStringNotContainsString('$__templateOptions', $compiled);
    }
}
