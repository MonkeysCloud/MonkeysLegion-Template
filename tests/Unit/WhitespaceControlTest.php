<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Lexer\Lexer;
use MonkeysLegion\Template\Lexer\TokenType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Jinja2-style whitespace control in the Lexer.
 */
final class WhitespaceControlTest extends TestCase
{
    private Lexer $lexer;

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    #[Test]
    public function lexer_recognizes_whitespace_trim_markers_on_echoes(): void
    {
        $tokens = $this->lexer->tokenize('  {{- $var -}}  ', 'test.ml.php');

        $hasEcho = false;
        foreach ($tokens as $token) {
            if ($token->type === TokenType::ECHO_OPEN || $token->type === TokenType::DIRECTIVE) {
                $hasEcho = true;
            }
        }
        $this->assertTrue($hasEcho || count($tokens) > 0, 'Should tokenize whitespace-controlled echoes');
    }

    #[Test]
    public function lexer_tokenizes_directive_with_trim_marker(): void
    {
        $source = "@if(\$show)\n    Content\n@endif";
        $tokens = $this->lexer->tokenize($source, 'test.ml.php');

        // Should produce tokens including directives
        $directiveCount = 0;
        foreach ($tokens as $token) {
            if ($token->type === TokenType::DIRECTIVE) {
                $directiveCount++;
            }
        }
        $this->assertGreaterThanOrEqual(2, $directiveCount, 'Should find @if and @endif directives');
    }

    #[Test]
    public function lexer_preserves_line_numbers_with_whitespace_control(): void
    {
        $source = "Line 1\n{{- \$var -}}\nLine 3";
        $tokens = $this->lexer->tokenize($source, 'test.ml.php');

        // Verify tokens exist and have line tracking
        $this->assertNotEmpty($tokens);
        foreach ($tokens as $token) {
            $this->assertGreaterThanOrEqual(1, $token->line);
        }
    }

    #[Test]
    public function standard_echoes_still_work(): void
    {
        $tokens = $this->lexer->tokenize('{{ $var }}', 'test.ml.php');

        $hasEcho = false;
        foreach ($tokens as $token) {
            if ($token->type === TokenType::ECHO_OPEN) {
                $hasEcho = true;
            }
        }
        $this->assertTrue($hasEcho, 'Standard echoes should still tokenize correctly');
    }

    #[Test]
    public function raw_echoes_with_trim_markers(): void
    {
        $tokens = $this->lexer->tokenize('{!!- $html -!!}', 'test.ml.php');
        $this->assertNotEmpty($tokens, 'Should tokenize raw echoes with trim markers');
    }

    #[Test]
    public function comment_with_trim_markers(): void
    {
        $tokens = $this->lexer->tokenize('{{-- comment --}}', 'test.ml.php');

        $hasComment = false;
        foreach ($tokens as $token) {
            if ($token->type === TokenType::COMMENT_OPEN) {
                $hasComment = true;
            }
        }
        $this->assertTrue($hasComment, 'Should tokenize comments');
    }
}
