<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Exceptions\TemplateSyntaxException;
use MonkeysLegion\Template\Exceptions\ViewException;
use MonkeysLegion\Template\Support\ErrorRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the rich development error renderer.
 */
final class ErrorRendererTest extends TestCase
{
    private ErrorRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new ErrorRenderer(devMode: true);
    }

    #[Test]
    public function dev_mode_flag(): void
    {
        $renderer = new ErrorRenderer(devMode: false);
        $this->assertFalse($renderer->isDevMode());

        $renderer->setDevMode(true);
        $this->assertTrue($renderer->isDevMode());
    }

    #[Test]
    public function production_error_is_minimal(): void
    {
        $renderer = new ErrorRenderer(devMode: false);
        $exception = new RuntimeException('Sensitive info');

        $output = $renderer->render($exception);

        $this->assertStringContainsString('500', $output);
        $this->assertStringContainsString('Server Error', $output);
        // Should NOT contain sensitive info
        $this->assertStringNotContainsString('Sensitive info', $output);
    }

    #[Test]
    public function dev_error_shows_exception_class(): void
    {
        $exception = new RuntimeException('Something went wrong');

        $output = $this->renderer->render($exception);

        $this->assertStringContainsString('RuntimeException', $output);
    }

    #[Test]
    public function dev_error_shows_message(): void
    {
        $exception = new RuntimeException('Template syntax error near line 42');

        $output = $this->renderer->render($exception);

        $this->assertStringContainsString('Template syntax error near line 42', $output);
    }

    #[Test]
    public function dev_error_shows_file_and_line(): void
    {
        $exception = new RuntimeException('Test');
        // The file/line will be this test file

        $output = $this->renderer->render($exception);

        $this->assertStringContainsString('ErrorRendererTest.php', $output);
    }

    #[Test]
    public function dev_error_contains_stack_trace(): void
    {
        $exception = new RuntimeException('Stack trace test');

        $output = $this->renderer->render($exception);

        $this->assertStringContainsString('Stack Trace', $output);
        // Should have at least one frame
        $this->assertStringContainsString('frame', $output);
    }

    #[Test]
    public function dev_error_contains_source_section(): void
    {
        $exception = new RuntimeException('Source test');

        $output = $this->renderer->render($exception);

        $this->assertStringContainsString('Source', $output);
        $this->assertStringContainsString('snippet', $output);
    }

    #[Test]
    public function dev_error_styled_with_css(): void
    {
        $exception = new RuntimeException('Style test');

        $output = $this->renderer->render($exception);

        // Should have CSS styling
        $this->assertStringContainsString('<style>', $output);
        $this->assertStringContainsString('</style>', $output);
    }

    #[Test]
    public function template_syntax_exception_shows_title(): void
    {
        $exception = new TemplateSyntaxException(
            'Unclosed @if directive',
            '/path/to/test.ml.php',
            42,
        );

        $output = $this->renderer->render($exception);

        $this->assertStringContainsString('TemplateSyntaxException', $output);
        $this->assertStringContainsString('Unclosed', $output);
    }

    #[Test]
    public function source_snippet_highlights_error_line(): void
    {
        // Create a temporary file so the snippet reader can find it
        $tmpFile = sys_get_temp_dir() . '/ml_error_test_' . uniqid() . '.php';
        file_put_contents($tmpFile, "<?php\nline 2\nline 3\nerror here\nline 5\nline 6\n");

        try {
            $exception = new RuntimeException('Test');
            // Override file/line via reflection
            $ref = new \ReflectionClass($exception);
            $fileProp = $ref->getProperty('file');
            $fileProp->setValue($exception, $tmpFile);
            $lineProp = $ref->getProperty('line');
            $lineProp->setValue($exception, 4);

            $snippet = $this->renderer->getSourceSnippet($exception);

            $this->assertStringContainsString('error here', $snippet);
            $this->assertStringContainsString('class="line error"', $snippet);
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function format_stack_trace_returns_html(): void
    {
        $exception = new RuntimeException('Trace test');

        $html = $this->renderer->formatStackTrace($exception);

        $this->assertStringContainsString('<div class="frame">', $html);
    }

    #[Test]
    public function html_escapes_dangerous_content(): void
    {
        $exception = new RuntimeException('<script>alert("xss")</script>');

        $output = $this->renderer->render($exception);

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }
}
