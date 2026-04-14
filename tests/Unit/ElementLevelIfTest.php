<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for HEEx-style :if element-level directive.
 */
final class ElementLevelIfTest extends TestCase
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
    public function if_on_opening_closing_tag(): void
    {
        $compiled = $this->compile('<span :if="$show" class="badge">Admin</span>');

        $this->assertStringContainsString('if ($show)', $compiled);
        $this->assertStringContainsString('endif;', $compiled);
        $this->assertStringContainsString('<span class="badge">', $compiled);
        $this->assertStringContainsString('Admin', $compiled);
    }

    #[Test]
    public function if_on_self_closing_tag(): void
    {
        $compiled = $this->compile('<hr :if="$showDivider" />');

        $this->assertStringContainsString('if ($showDivider)', $compiled);
        $this->assertStringContainsString('<hr', $compiled);
        $this->assertStringContainsString('endif;', $compiled);
    }

    #[Test]
    public function if_removes_attribute_from_output(): void
    {
        $compiled = $this->compile('<div :if="$visible" class="box">Content</div>');

        // The :if attribute should be removed from the rendered tag
        $this->assertStringNotContainsString(':if=', $compiled);
        $this->assertStringContainsString('class="box"', $compiled);
    }

    #[Test]
    public function if_with_method_call_expression(): void
    {
        $compiled = $this->compile('<span :if="$user->isAdmin()" class="admin">Admin</span>');

        $this->assertStringContainsString('if ($user->isAdmin())', $compiled);
    }

    #[Test]
    public function unless_on_element(): void
    {
        $compiled = $this->compile('<div :unless="$hidden" class="visible">Show</div>');

        $this->assertStringContainsString('if (!($hidden))', $compiled);
        $this->assertStringNotContainsString(':unless=', $compiled);
    }

    #[Test]
    public function unless_on_self_closing_tag(): void
    {
        $compiled = $this->compile('<input :unless="$readonly" type="text" />');

        $this->assertStringContainsString('if (!($readonly))', $compiled);
        $this->assertStringContainsString('type="text"', $compiled);
    }
}
