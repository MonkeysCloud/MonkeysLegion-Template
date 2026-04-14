<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for inline string rendering (no file required).
 */
class RenderStringTest extends TestCase
{
    #[Test]
    public function renders_simple_string(): void
    {
        $output = $this->renderer->renderString('<p>Hello</p>');

        $this->assertStringContainsString('Hello', $output);
    }

    #[Test]
    public function renders_string_with_variables(): void
    {
        $output = $this->renderer->renderString(
            '<p>Hello {{ $name }}</p>',
            ['name' => 'World'],
        );

        $this->assertStringContainsString('Hello', $output);
        $this->assertStringContainsString('World', $output);
    }

    #[Test]
    public function renders_string_with_directives(): void
    {
        $template = <<<'ML'
@if($show)
<p>Visible</p>
@endif
ML;

        $output = $this->renderer->renderString($template, ['show' => true]);

        $this->assertStringContainsString('Visible', $output);
    }

    #[Test]
    public function renders_string_with_hidden_directive(): void
    {
        $template = <<<'ML'
@if($show)
<p>Visible</p>
@endif
ML;

        $output = $this->renderer->renderString($template, ['show' => false]);

        $this->assertStringNotContainsString('Visible', $output);
    }

    #[Test]
    public function renders_loop_in_string(): void
    {
        $template = <<<'ML'
@foreach($items as $item)
<li>{{ $item }}</li>
@endforeach
ML;

        $output = $this->renderer->renderString($template, ['items' => ['A', 'B', 'C']]);

        $this->assertStringContainsString('A', $output);
        $this->assertStringContainsString('B', $output);
        $this->assertStringContainsString('C', $output);
    }

    #[Test]
    public function renders_empty_string(): void
    {
        $output = $this->renderer->renderString('', []);

        // Empty string renders to empty or near-empty output
        $trimmed = trim($output);
        $this->assertSame('', $trimmed);
    }

    #[Test]
    public function renders_raw_html_in_string(): void
    {
        $output = $this->renderer->renderString(
            '{!! $html !!}',
            ['html' => '<strong>Bold</strong>'],
        );

        $this->assertStringContainsString('<strong>Bold</strong>', $output);
    }
}
