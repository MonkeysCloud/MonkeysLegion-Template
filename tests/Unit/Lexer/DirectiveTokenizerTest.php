<?php

declare(strict_types=1);

namespace Tests\Unit\Lexer;

use MonkeysLegion\Template\Exceptions\TemplateSyntaxException;
use MonkeysLegion\Template\Lexer\DirectiveTokenizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DirectiveTokenizer::class)]
final class DirectiveTokenizerTest extends TestCase
{
    #[Test]
    public function parse_simple_directive_without_args(): void
    {
        $dt = new DirectiveTokenizer('@endif');

        $this->assertSame('endif', $dt->name);
        $this->assertSame('', $dt->arguments);
        $this->assertFalse($dt->hasArguments);
    }

    #[Test]
    public function parse_directive_with_simple_args(): void
    {
        $dt = new DirectiveTokenizer("@if(\$condition)");

        $this->assertSame('if', $dt->name);
        $this->assertSame('$condition', $dt->arguments);
        $this->assertTrue($dt->hasArguments);
    }

    #[Test]
    public function parse_directive_with_nested_parentheses(): void
    {
        $dt = new DirectiveTokenizer("@style(['color: red' => (\$count ?? 0) > 0])");

        $this->assertSame('style', $dt->name);
        $this->assertStringContainsString("'color: red'", $dt->arguments);
        $this->assertStringContainsString('($count ?? 0)', $dt->arguments);
    }

    #[Test]
    public function parse_directive_with_deeply_nested_parentheses(): void
    {
        $dt = new DirectiveTokenizer("@json(array_map(fn(\$x) => strtoupper(trim(\$x)), \$items))");

        $this->assertSame('json', $dt->name);
        $this->assertStringContainsString('array_map', $dt->arguments);
        $this->assertStringContainsString("strtoupper(trim(\$x))", $dt->arguments);
    }

    #[Test]
    public function split_arguments_simple(): void
    {
        $dt = new DirectiveTokenizer("@section('title', 'My Page')");

        $args = $dt->splitArguments();
        $this->assertCount(2, $args);
        $this->assertSame("'title'", $args[0]);
        $this->assertSame("'My Page'", $args[1]);
    }

    #[Test]
    public function split_arguments_with_nested_arrays(): void
    {
        $dt = new DirectiveTokenizer("@props(['title' => 'Default', 'items' => []])");

        $args = $dt->splitArguments();
        $this->assertCount(1, $args); // It's a single array argument
        $this->assertStringContainsString("'title'", $args[0]);
    }

    #[Test]
    public function split_arguments_respects_strings_with_commas(): void
    {
        $dt = new DirectiveTokenizer("@include('partials.header', ['title' => 'Hello, World'])");

        $args = $dt->splitArguments();
        $this->assertCount(2, $args);
        $this->assertSame("'partials.header'", $args[0]);
        $this->assertStringContainsString("'Hello, World'", $args[1]);
    }

    #[Test]
    public function get_first_argument_strips_quotes(): void
    {
        $dt = new DirectiveTokenizer("@yield('content')");

        $this->assertSame('content', $dt->getFirstArgument());
    }

    #[Test]
    public function get_first_argument_returns_null_when_no_args(): void
    {
        $dt = new DirectiveTokenizer('@endif');

        $this->assertNull($dt->getFirstArgument());
    }

    #[Test]
    public function get_second_argument_works(): void
    {
        $dt = new DirectiveTokenizer("@yield('title', 'Default Title')");

        $this->assertSame('title', $dt->getFirstArgument());
        $this->assertSame('Default Title', $dt->getSecondArgument());
    }

    #[Test]
    public function get_second_argument_returns_null_when_single_arg(): void
    {
        $dt = new DirectiveTokenizer("@yield('content')");

        $this->assertNull($dt->getSecondArgument());
    }

    #[Test]
    public function parse_directive_with_whitespace_control_marker(): void
    {
        $dt = new DirectiveTokenizer('@-if($condition)');

        $this->assertSame('if', $dt->name);
        $this->assertSame('$condition', $dt->arguments);
    }

    #[Test]
    public function parse_foreach_directive(): void
    {
        $dt = new DirectiveTokenizer('@foreach($items as $item)');

        $this->assertSame('foreach', $dt->name);
        $this->assertSame('$items as $item', $dt->arguments);
    }

    #[Test]
    public function parse_directive_with_arrow_function(): void
    {
        $dt = new DirectiveTokenizer("@json(collect(\$items)->map(fn(\$i) => \$i->name)->all())");

        $this->assertSame('json', $dt->name);
        $this->assertStringContainsString('collect($items)', $dt->arguments);
        $this->assertStringContainsString('->all()', $dt->arguments);
    }

    #[Test]
    #[DataProvider('balancedParenthesesProvider')]
    public function parse_various_balanced_expressions(string $directive, string $expectedName, string $expectedContains): void
    {
        $dt = new DirectiveTokenizer($directive);

        $this->assertSame($expectedName, $dt->name);
        $this->assertStringContainsString($expectedContains, $dt->arguments);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function balancedParenthesesProvider(): iterable
    {
        yield 'ternary' => [
            '@class([$active ? "yes" : "no"])',
            'class',
            '$active',
        ];

        yield 'null coalesce' => [
            '@style(["color: " . ($color ?? "red")])',
            'style',
            '$color ?? "red"',
        ];

        yield 'method chain' => [
            '@json($user->roles()->pluck("name")->toArray())',
            'json',
            'pluck("name")',
        ];

        yield 'nested array' => [
            "@class(['btn', 'btn-' . (\$size ?? 'md'), 'active' => \$isActive])",
            'class',
            "btn",
        ];
    }
}
