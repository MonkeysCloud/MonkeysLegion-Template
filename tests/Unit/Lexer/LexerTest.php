<?php

declare(strict_types=1);

namespace Tests\Unit\Lexer;

use MonkeysLegion\Template\Exceptions\TemplateSyntaxException;
use MonkeysLegion\Template\Lexer\Lexer;
use MonkeysLegion\Template\Lexer\Token;
use MonkeysLegion\Template\Lexer\TokenType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Lexer::class)]
#[CoversClass(Token::class)]
#[CoversClass(TokenType::class)]
final class LexerTest extends TestCase
{
    private Lexer $lexer;

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    #[Test]
    public function tokenize_plain_text_returns_text_and_eof(): void
    {
        $tokens = $this->lexer->tokenize('<div>Hello</div>');

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::TEXT, $tokens[0]->type);
        $this->assertSame('<div>Hello</div>', $tokens[0]->value);
        $this->assertSame(TokenType::EOF, $tokens[1]->type);
    }

    #[Test]
    public function tokenize_escaped_echo_produces_three_tokens(): void
    {
        $tokens = $this->lexer->tokenize('{{ $name }}');

        $this->assertSame(TokenType::ECHO_OPEN, $tokens[0]->type);
        $this->assertSame('{{', $tokens[0]->value);
        $this->assertSame(TokenType::TEXT, $tokens[1]->type);
        $this->assertSame('$name', $tokens[1]->value);
        $this->assertSame(TokenType::ECHO_CLOSE, $tokens[2]->type);
    }

    #[Test]
    public function tokenize_raw_echo_produces_three_tokens(): void
    {
        $tokens = $this->lexer->tokenize('{!! $html !!}');

        $this->assertSame(TokenType::RAW_ECHO_OPEN, $tokens[0]->type);
        $this->assertSame(TokenType::TEXT, $tokens[1]->type);
        $this->assertSame('$html', $tokens[1]->value);
        $this->assertSame(TokenType::RAW_ECHO_CLOSE, $tokens[2]->type);
    }

    #[Test]
    public function tokenize_comment_produces_single_token(): void
    {
        $tokens = $this->lexer->tokenize('{{-- This is a comment --}}');

        $this->assertSame(TokenType::COMMENT_OPEN, $tokens[0]->type);
        $this->assertStringContains('This is a comment', $tokens[0]->value);
    }

    #[Test]
    public function tokenize_directive_without_args(): void
    {
        $tokens = $this->lexer->tokenize('@endif');

        $this->assertSame(TokenType::DIRECTIVE, $tokens[0]->type);
        $this->assertSame('@endif', $tokens[0]->value);
    }

    #[Test]
    public function tokenize_directive_with_simple_args(): void
    {
        $tokens = $this->lexer->tokenize('@if($condition)');

        $this->assertSame(TokenType::DIRECTIVE, $tokens[0]->type);
        $this->assertSame('@if($condition)', $tokens[0]->value);
    }

    #[Test]
    public function tokenize_directive_with_nested_parentheses(): void
    {
        $source = "@style(['color: red' => (\$count ?? 0) > 0])";
        $tokens = $this->lexer->tokenize($source);

        $this->assertSame(TokenType::DIRECTIVE, $tokens[0]->type);
        $this->assertSame($source, $tokens[0]->value);
    }

    #[Test]
    public function tokenize_deeply_nested_parentheses(): void
    {
        $source = "@json(array_map(fn(\$x) => strtoupper(trim(\$x)), \$items))";
        $tokens = $this->lexer->tokenize($source);

        $this->assertSame(TokenType::DIRECTIVE, $tokens[0]->type);
        $this->assertSame($source, $tokens[0]->value);
    }

    #[Test]
    public function tokenize_component_open_tag(): void
    {
        $tokens = $this->lexer->tokenize('<x-alert type="error">');

        $this->assertSame(TokenType::COMPONENT_OPEN, $tokens[0]->type);
        $this->assertSame('<x-alert type="error">', $tokens[0]->value);
    }

    #[Test]
    public function tokenize_component_self_closing(): void
    {
        $tokens = $this->lexer->tokenize('<x-icon name="check" />');

        $this->assertSame(TokenType::COMPONENT_SELF_CLOSE, $tokens[0]->type);
    }

    #[Test]
    public function tokenize_component_close_tag(): void
    {
        $tokens = $this->lexer->tokenize('</x-alert>');

        $this->assertSame(TokenType::COMPONENT_CLOSE, $tokens[0]->type);
    }

    #[Test]
    public function tokenize_tracks_line_numbers(): void
    {
        $source = "Line 1\nLine 2\n{{ \$var }}";
        $tokens = $this->lexer->tokenize($source);

        // Text token starts at line 1
        $this->assertSame(1, $tokens[0]->line);

        // Echo open starts at line 3
        $echoOpen = $this->findTokenByType($tokens, TokenType::ECHO_OPEN);
        $this->assertNotNull($echoOpen);
        $this->assertSame(3, $echoOpen->line);
    }

    #[Test]
    public function tokenize_tracks_column_numbers(): void
    {
        $source = "Hello {{ \$name }}";
        $tokens = $this->lexer->tokenize($source);

        // "Hello " is TEXT at col 1
        $this->assertSame(1, $tokens[0]->column);

        // {{ starts at col 7
        $this->assertSame(7, $tokens[1]->column);
    }

    #[Test]
    public function tokenize_escaped_at_produces_literal(): void
    {
        $tokens = $this->lexer->tokenize('@@if');

        $this->assertSame(TokenType::TEXT, $tokens[0]->type);
        $this->assertSame('@if', $tokens[0]->value);
    }

    #[Test]
    public function tokenize_whitespace_control_trim_left_echo(): void
    {
        $tokens = $this->lexer->tokenize('{{- $name }}');

        $this->assertTrue($tokens[0]->trimLeft);
    }

    #[Test]
    public function tokenize_whitespace_control_trim_right_echo(): void
    {
        $tokens = $this->lexer->tokenize('{{ $name -}}');

        // Last token (ECHO_CLOSE) should have trimRight
        $closeToken = $this->findTokenByType($tokens, TokenType::ECHO_CLOSE);
        $this->assertNotNull($closeToken);
        $this->assertTrue($closeToken->trimRight);
    }

    #[Test]
    public function tokenize_unclosed_echo_throws_syntax_exception(): void
    {
        $this->expectException(TemplateSyntaxException::class);
        $this->expectExceptionMessage('Unclosed echo');

        $this->lexer->tokenize('{{ $name');
    }

    #[Test]
    public function tokenize_unclosed_comment_throws_syntax_exception(): void
    {
        $this->expectException(TemplateSyntaxException::class);
        $this->expectExceptionMessage('Unclosed comment');

        $this->lexer->tokenize('{{-- unclosed comment');
    }

    #[Test]
    public function tokenize_unclosed_raw_echo_throws_syntax_exception(): void
    {
        $this->expectException(TemplateSyntaxException::class);
        $this->expectExceptionMessage('Unclosed raw echo');

        $this->lexer->tokenize('{!! $html');
    }

    #[Test]
    public function tokenize_unclosed_directive_parens_throws_syntax_exception(): void
    {
        $this->expectException(TemplateSyntaxException::class);
        $this->expectExceptionMessage('Unclosed parenthesis');

        $this->lexer->tokenize('@if($condition && (true)');
    }

    #[Test]
    public function validate_block_directives_detects_unclosed_if(): void
    {
        $tokens = $this->lexer->tokenize("@if(\$x)\nHello\n");

        $this->expectException(TemplateSyntaxException::class);
        $this->expectExceptionMessage('Unclosed @if');

        $this->lexer->validateBlockDirectives($tokens);
    }

    #[Test]
    public function validate_block_directives_detects_mismatched_closer(): void
    {
        $tokens = $this->lexer->tokenize("@if(\$x)\nHello\n@endforeach");

        $this->expectException(TemplateSyntaxException::class);
        $this->expectExceptionMessage('Expected @endif');

        $this->lexer->validateBlockDirectives($tokens);
    }

    #[Test]
    public function validate_block_directives_passes_for_matched_pairs(): void
    {
        $tokens = $this->lexer->tokenize("@if(\$x)\nHello\n@endif");

        // Should not throw
        $this->lexer->validateBlockDirectives($tokens);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validate_block_directives_handles_nested_blocks(): void
    {
        $source = "@if(\$a)\n@foreach(\$items as \$item)\n{{ \$item }}\n@endforeach\n@endif";
        $tokens = $this->lexer->tokenize($source);

        // Should not throw
        $this->lexer->validateBlockDirectives($tokens);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function token_length_property_hook_works(): void
    {
        $token = new Token(TokenType::TEXT, 'hello', 1, 1);

        $this->assertSame(5, $token->length());
    }

    #[Test]
    public function token_is_method_works(): void
    {
        $token = new Token(TokenType::DIRECTIVE, '@if($x)', 1, 1);

        $this->assertTrue($token->is(TokenType::DIRECTIVE));
        $this->assertFalse($token->is(TokenType::TEXT));
    }

    #[Test]
    public function token_is_directive_method_works(): void
    {
        $token = new Token(TokenType::DIRECTIVE, '@foreach($items as $item)', 1, 1);

        $this->assertTrue($token->isDirective('foreach'));
        $this->assertFalse($token->isDirective('if'));
    }

    #[Test]
    public function tokenize_complex_template(): void
    {
        $source = <<<'TEMPLATE'
        @extends('layouts.app')

        @section('content')
            <div class="container">
                @foreach($items as $item)
                    <x-card :title="$item->name">
                        {{ $item->description }}
                    </x-card>
                @endforeach
            </div>
        @endsection
        TEMPLATE;

        $tokens = $this->lexer->tokenize($source);

        // Should tokenize without errors
        $directiveTokens = array_filter(
            $tokens,
            fn(Token $t) => $t->type === TokenType::DIRECTIVE,
        );
        $this->assertGreaterThanOrEqual(5, count($directiveTokens));

        // Should have component tokens
        $componentTokens = array_filter(
            $tokens,
            fn(Token $t) => in_array($t->type, [TokenType::COMPONENT_OPEN, TokenType::COMPONENT_CLOSE], true),
        );
        $this->assertCount(2, $componentTokens);
    }

    /**
     * Helper to find the first token of a specific type.
     *
     * @param Token[] $tokens
     */
    private function findTokenByType(array $tokens, TokenType $type): ?Token
    {
        foreach ($tokens as $token) {
            if ($token->type === $type) {
                return $token;
            }
        }
        return null;
    }

    /**
     * Custom assertion for string containment.
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertStringContainsString($needle, $haystack);
    }
}
