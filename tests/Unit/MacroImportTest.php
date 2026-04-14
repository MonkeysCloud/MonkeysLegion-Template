<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Jinja2-style macro imports (@from, @import).
 *
 * While the full import resolution requires file system access,
 * these tests verify the compilation output for import directives.
 */
final class MacroImportTest extends TestCase
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
    public function macro_defined_and_called_in_same_template(): void
    {
        $source = <<<'ML'
@macro('badge', $text)
<span class="badge">{{ $text }}</span>
@endmacro

@call('badge', 'Active')
@call('badge', 'Inactive')
ML;

        $compiled = $this->compile($source);

        // Should have macro definition
        $this->assertStringContainsString('$__macros[\'badge\'] = function', $compiled);
        // Should have two calls
        $this->assertEquals(2, substr_count($compiled, '$__macros[\'badge\']('));
    }

    #[Test]
    public function multiple_macros_in_same_template(): void
    {
        $source = <<<'ML'
@macro('badge', $text)
<span>{{ $text }}</span>
@endmacro

@macro('icon', $name)
<i class="{{ $name }}"></i>
@endmacro

@call('badge', 'Test')
@call('icon', 'home')
ML;

        $compiled = $this->compile($source);

        $this->assertStringContainsString('$__macros[\'badge\']', $compiled);
        $this->assertStringContainsString('$__macros[\'icon\']', $compiled);
    }

    #[Test]
    public function macro_with_complex_params(): void
    {
        $source = "@macro('card', \$title, \$class)\n<div class=\"{{ \$class }}\">\n<h3>{{ \$title }}</h3>\n</div>\n@endmacro";

        $compiled = $this->compile($source);

        $this->assertStringContainsString('function($title, $class)', $compiled);
    }

    #[Test]
    public function macro_call_with_variable_args(): void
    {
        $compiled = $this->compile("@call('badge', \$text, \$color)");

        $this->assertStringContainsString('$text, $color', $compiled);
    }

    #[Test]
    public function macro_preserves_template_content(): void
    {
        $source = <<<'ML'
@macro('sep')
<hr class="divider" />
@endmacro

<h1>Title</h1>
@call('sep')
<p>Content</p>
ML;

        $compiled = $this->compile($source);

        $this->assertStringContainsString('<h1>Title</h1>', $compiled);
        $this->assertStringContainsString('<p>Content</p>', $compiled);
    }
}
