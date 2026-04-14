<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use MonkeysLegion\Template\Exceptions\TemplateSyntaxException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TemplateSyntaxException::class)]
final class TemplateSyntaxExceptionTest extends TestCase
{
    #[Test]
    public function properties_are_accessible(): void
    {
        $e = new TemplateSyntaxException(
            'Unclosed directive',
            '/views/home.ml.php',
            14,
            5,
        );

        $this->assertSame('/views/home.ml.php', $e->templatePath);
        $this->assertSame(14, $e->templateLine);
        $this->assertSame(5, $e->templateColumn);
        $this->assertSame('Unclosed directive', $e->getErrorMessage());
    }

    #[Test]
    public function template_name_returns_basename(): void
    {
        $e = new TemplateSyntaxException(
            'Error',
            '/long/path/to/views/dashboard.ml.php',
            1,
        );

        $this->assertSame('dashboard.ml.php', $e->getTemplateName());
    }

    #[Test]
    public function formatted_message_includes_template_name_and_line(): void
    {
        $e = new TemplateSyntaxException(
            'Unexpected token',
            '/views/home.ml.php',
            10,
            3,
        );

        $message = $e->getMessage();

        $this->assertStringContainsString('home.ml.php', $message);
        $this->assertStringContainsString('line 10', $message);
        $this->assertStringContainsString('column 3', $message);
        $this->assertStringContainsString('Unexpected token', $message);
    }

    #[Test]
    public function snippet_shows_context_lines(): void
    {
        $source = implode("\n", [
            '<html>',
            '<body>',
            '    <div>',
            '        @foreach($items as $item)',
            '            @style([broken)',
            '        @endforeach',
            '    </div>',
            '</body>',
            '</html>',
        ]);

        $e = new TemplateSyntaxException(
            'Unclosed bracket',
            '/views/test.ml.php',
            5,
            13,
            $source,
        );

        $snippet = $e->getSnippet();

        // Should contain the error line with > marker
        $this->assertStringContainsString('>', $snippet);
        $this->assertStringContainsString('@style([broken)', $snippet);

        // Should show context lines above and below
        $this->assertStringContainsString('@foreach', $snippet);
        $this->assertStringContainsString('@endforeach', $snippet);
    }

    #[Test]
    public function snippet_with_column_pointer(): void
    {
        $source = "line1\nline2\n    @bad(";

        $e = new TemplateSyntaxException(
            'Error',
            '/views/test.ml.php',
            3,
            5,
            $source,
        );

        $snippet = $e->getSnippet();

        // Should have the ^ pointer
        $this->assertStringContainsString('^', $snippet);
    }

    #[Test]
    public function snippet_is_empty_when_no_source(): void
    {
        $e = new TemplateSyntaxException(
            'Error',
            '/views/test.ml.php',
            1,
        );

        $this->assertSame('', $e->getSnippet());
    }

    #[Test]
    public function file_and_line_are_overridden(): void
    {
        $e = new TemplateSyntaxException(
            'Error',
            '/views/home.ml.php',
            42,
        );

        $this->assertSame('/views/home.ml.php', $e->getFile());
        $this->assertSame(42, $e->getLine());
    }

    #[Test]
    public function wraps_previous_exception(): void
    {
        $previous = new \RuntimeException('low-level error');

        $e = new TemplateSyntaxException(
            'Parse error',
            '/views/home.ml.php',
            1,
            1,
            '',
            $previous,
        );

        $this->assertSame($previous, $e->getPrevious());
    }
}
