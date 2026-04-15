<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Lexer\Lexer;
use MonkeysLegion\Template\Lexer\Token;
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

    /**
     * @param Token[] $tokens
     * @return Token[]
     */
    private function filterByType(array $tokens, TokenType $type): array
    {
        return array_values(array_filter($tokens, static fn(Token $t): bool => $t->type === $type));
    }

    #[Test]
    public function lexer_recognizes_whitespace_trim_markers_on_echoes(): void
    {
        $tokens = $this->lexer->tokenize('  {{- $var -}}  ', 'test.ml.php');

        $echoTokens = $this->filterByType($tokens, TokenType::ECHO_OPEN);
        $this->assertNotEmpty($echoTokens, 'Should find ECHO_OPEN token for {{- $var -}}');
        $this->assertTrue($echoTokens[0]->trimLeft, 'ECHO_OPEN with {{- should have trimLeft=true');
    }

    #[Test]
    public function lexer_tokenizes_directive_with_correct_types(): void
    {
        $source = "@if(\$show)\n    Content\n@endif";
        $tokens = $this->lexer->tokenize($source, 'test.ml.php');

        $directives = $this->filterByType($tokens, TokenType::DIRECTIVE);
        $this->assertGreaterThanOrEqual(2, count($directives), 'Should find @if and @endif directives');
        $this->assertStringContainsString('if', $directives[0]->value);
    }

    #[Test]
    public function lexer_preserves_line_numbers_with_whitespace_control(): void
    {
        $source = "Line 1\n{{- \$var -}}\nLine 3";
        $tokens = $this->lexer->tokenize($source, 'test.ml.php');

        $this->assertNotEmpty($tokens);

        $echoTokens = $this->filterByType($tokens, TokenType::ECHO_OPEN);
        if (count($echoTokens) > 0) {
            $this->assertEquals(2, $echoTokens[0]->line, 'Echo token should be on line 2');
        }

        foreach ($tokens as $token) {
            $this->assertGreaterThanOrEqual(1, $token->line);
        }
    }

    #[Test]
    public function standard_echoes_produce_echo_open_token(): void
    {
        $tokens = $this->lexer->tokenize('{{ $var }}', 'test.ml.php');

        $echoTokens = $this->filterByType($tokens, TokenType::ECHO_OPEN);
        $this->assertCount(1, $echoTokens, 'Standard {{ }} should produce exactly one ECHO_OPEN token');
        $this->assertFalse($echoTokens[0]->trimLeft, 'Standard echo should not trim left');
        $this->assertFalse($echoTokens[0]->trimRight, 'Standard echo should not trim right');
    }

    #[Test]
    public function raw_echoes_with_trim_markers(): void
    {
        $tokens = $this->lexer->tokenize('{!!- $html -!!}', 'test.ml.php');
        $this->assertNotEmpty($tokens, 'Should tokenize raw echoes with trim markers');
    }

    #[Test]
    public function comment_produces_comment_open_token(): void
    {
        $tokens = $this->lexer->tokenize('{{-- comment --}}', 'test.ml.php');

        $commentTokens = $this->filterByType($tokens, TokenType::COMMENT_OPEN);
        $this->assertCount(1, $commentTokens, 'Should produce exactly one COMMENT_OPEN token');
    }

    #[Test]
    public function token_ordering_is_preserved(): void
    {
        $source = "<h1>{{ \$title }}</h1>\n@if(\$show)\n<p>Content</p>\n@endif";
        $tokens = $this->lexer->tokenize($source, 'test.ml.php');

        $types = array_map(static fn(Token $t): TokenType => $t->type, $tokens);

        $this->assertContains(TokenType::TEXT, $types, 'Should contain TEXT tokens');
        $this->assertContains(TokenType::ECHO_OPEN, $types, 'Should contain ECHO_OPEN token');
        $this->assertContains(TokenType::DIRECTIVE, $types, 'Should contain DIRECTIVE tokens');
    }
}
