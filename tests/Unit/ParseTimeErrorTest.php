<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Lexer\Lexer;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for parse-time error detection via the Lexer.
 */
final class ParseTimeErrorTest extends TestCase
{
    private Lexer $lexer;

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    #[Test]
    public function unclosed_echo_throws(): void
    {
        $this->expectException(\MonkeysLegion\Template\Exceptions\TemplateSyntaxException::class);
        $this->expectExceptionMessage('Unclosed');

        $tokens = $this->lexer->tokenize('Hello {{ $name', 'test.ml.php');
        $this->lexer->validateBlockDirectives($tokens);
    }

    #[Test]
    public function unclosed_raw_echo_throws(): void
    {
        $this->expectException(\MonkeysLegion\Template\Exceptions\TemplateSyntaxException::class);

        $tokens = $this->lexer->tokenize('Hello {!! $name', 'test.ml.php');
        $this->lexer->validateBlockDirectives($tokens);
    }

    #[Test]
    public function unclosed_comment_throws(): void
    {
        $this->expectException(\MonkeysLegion\Template\Exceptions\TemplateSyntaxException::class);

        $tokens = $this->lexer->tokenize('Hello {{-- comment without end', 'test.ml.php');
        $this->lexer->validateBlockDirectives($tokens);
    }

    #[Test]
    public function unmatched_if_throws(): void
    {
        $this->expectException(\MonkeysLegion\Template\Exceptions\TemplateSyntaxException::class);
        $this->expectExceptionMessage('@if');

        $tokens = $this->lexer->tokenize('@if($check) Content', 'test.ml.php');
        $this->lexer->validateBlockDirectives($tokens);
    }

    #[Test]
    public function unmatched_foreach_throws(): void
    {
        $this->expectException(\MonkeysLegion\Template\Exceptions\TemplateSyntaxException::class);
        $this->expectExceptionMessage('@foreach');

        $tokens = $this->lexer->tokenize('@foreach($items as $item) Content', 'test.ml.php');
        $this->lexer->validateBlockDirectives($tokens);
    }

    #[Test]
    public function unmatched_endif_throws(): void
    {
        $this->expectException(\MonkeysLegion\Template\Exceptions\TemplateSyntaxException::class);

        $tokens = $this->lexer->tokenize('Content @endif', 'test.ml.php');
        $this->lexer->validateBlockDirectives($tokens);
    }

    #[Test]
    public function balanced_directives_pass(): void
    {
        $tokens = $this->lexer->tokenize('@if($x) Hello @endif', 'test.ml.php');
        $this->lexer->validateBlockDirectives($tokens);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function nested_balanced_directives_pass(): void
    {
        $tokens = $this->lexer->tokenize(
            '@if($a) @foreach($b as $c) {{ $c }} @endforeach @endif',
            'test.ml.php',
        );
        $this->lexer->validateBlockDirectives($tokens);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function exception_includes_template_name(): void
    {
        try {
            $tokens = $this->lexer->tokenize('@if($x) Missing end', 'dashboard.ml.php');
            $this->lexer->validateBlockDirectives($tokens);
            $this->fail('Expected exception was not thrown');
        } catch (\MonkeysLegion\Template\Exceptions\TemplateSyntaxException $e) {
            $this->assertStringContainsString('dashboard.ml.php', $e->getMessage());
        }
    }

    #[Test]
    public function exception_includes_line_number(): void
    {
        try {
            $source = "Line 1\nLine 2\n@if(\$x)\nLine 4";
            $tokens = $this->lexer->tokenize($source, 'test.ml.php');
            $this->lexer->validateBlockDirectives($tokens);
            $this->fail('Expected exception was not thrown');
        } catch (\MonkeysLegion\Template\Exceptions\TemplateSyntaxException $e) {
            $this->assertGreaterThan(0, $e->templateLine);
        }
    }
}
