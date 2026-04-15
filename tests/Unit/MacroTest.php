<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for @macro/@endmacro/@call template macros (Twig/Jinja2-style).
 */
final class MacroTest extends TestCase
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
    public function macro_definition_compiles(): void
    {
        $compiled = $this->compile("@macro('badge', \$text, \$color)\n<span>{{ \$text }}</span>\n@endmacro");

        $this->assertStringContainsString('$__macros[\'badge\']', $compiled);
        $this->assertStringContainsString('function($text, $color)', $compiled);
        $this->assertStringContainsString('ob_start()', $compiled);
        $this->assertStringContainsString('ob_get_clean()', $compiled);
    }

    #[Test]
    public function call_directive_compiles(): void
    {
        $compiled = $this->compile("@call('badge', 'Active', 'green')");

        $this->assertStringContainsString('$__macros[\'badge\']', $compiled);
        $this->assertStringContainsString("'Active', 'green'", $compiled);
    }

    #[Test]
    public function call_without_args(): void
    {
        $compiled = $this->compile("@call('separator')");

        $this->assertStringContainsString('$__macros[\'separator\']', $compiled);
    }

    #[Test]
    public function macro_with_single_param(): void
    {
        $compiled = $this->compile("@macro('icon', \$name)\n<i class=\"icon-{{ \$name }}\"></i>\n@endmacro");

        $this->assertStringContainsString('function($name)', $compiled);
    }

    #[Test]
    public function macro_produces_executable_php(): void
    {
        $compiled = $this->compile(
            "@macro('greet', \$name)\nHello {{ \$name }}!\n@endmacro\n@call('greet', 'World')"
        );

        // Should contain both macro definition and call
        $this->assertStringContainsString('$__macros[\'greet\'] = function', $compiled);
        $this->assertStringContainsString('$__macros[\'greet\'](\'World\')', $compiled);
    }

    #[Test]
    public function undefined_macro_call_returns_empty(): void
    {
        $compiled = $this->compile("@call('nonexistent')");

        // Should have the isset guard
        $this->assertStringContainsString('isset($__macros[\'nonexistent\'])', $compiled);
    }
}
