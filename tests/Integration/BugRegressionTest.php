<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use MonkeysLegion\Template\Renderer;
use Tests\TestCase;

/**
 * Regression tests for the three reported bugs:
 * 1. Nested parentheses in @style/@class/@json/@js directives
 * 2. Shorthand @section('key', 'value') not supported
 * 3. @yield('title', 'Default') not supported
 */
#[CoversClass(Compiler::class)]
#[CoversClass(Parser::class)]
final class BugRegressionTest extends TestCase
{
    /**
     * Bug #1: @style with nested parentheses should compile correctly.
     *
     * Before fix: /@style\((.+?)\)/ would terminate early at the first )
     * causing a PHP syntax error in the compiled output.
     */
    #[Test]
    public function bug1_style_with_nested_parentheses_compiles_correctly(): void
    {
        $this->createView('bug1', <<<'TEMPLATE'
        <div style="@style(['display: block', 'color: red' => ($hasError ?? false)])">
            Content
        </div>
        TEMPLATE);

        $html = $this->renderer->render('bug1', ['hasError' => true]);

        $this->assertStringContainsString('color: red', $html);
    }

    /**
     * Bug #1 variant: @class with nested ternary expression.
     */
    #[Test]
    public function bug1_class_with_nested_parentheses_compiles_correctly(): void
    {
        $this->createView('bug1b', <<<'TEMPLATE'
        <div class="@class(['btn', 'active' => ($isActive ?? false)])">
            Content
        </div>
        TEMPLATE);

        $html = $this->renderer->render('bug1b', ['isActive' => true]);

        $this->assertStringContainsString('active', $html);
    }

    /**
     * Bug #1 variant: @json with nested function calls.
     */
    #[Test]
    public function bug1_json_with_nested_function_calls_compiles_correctly(): void
    {
        $this->createView('bug1c', <<<'TEMPLATE'
        <script>const data = @json(array_values(array_filter($items)));</script>
        TEMPLATE);

        $html = $this->renderer->render('bug1c', ['items' => ['a' => 1, 'b' => null, 'c' => 3]]);

        $this->assertStringContainsString('[1,3]', $html);
    }

    /**
     * Bug #1 variant: @js with method chaining and closures.
     */
    #[Test]
    public function bug1_js_with_closure_compiles_correctly(): void
    {
        $this->createView('bug1d', <<<'TEMPLATE'
        <script>const names = @js(array_map(fn($x) => strtoupper($x), $items));</script>
        TEMPLATE);

        $html = $this->renderer->render('bug1d', ['items' => ['hello', 'world']]);

        $this->assertStringContainsString('"HELLO"', $html);
        $this->assertStringContainsString('"WORLD"', $html);
    }

    /**
     * Bug #2: Shorthand @section('key', 'value') should work without @endsection.
     */
    #[Test]
    public function bug2_section_shorthand_works(): void
    {
        $this->createView('layouts.simple', <<<'TEMPLATE'
        <title>@yield('title')</title>
        <body>@yield('content')</body>
        TEMPLATE);

        $this->createView('bug2', <<<'TEMPLATE'
        @extends('layouts.simple')
        @section('title', 'My Page Title')
        @section('content')
        <p>Hello World</p>
        @endsection
        TEMPLATE);

        $html = $this->renderer->render('bug2');

        $this->assertStringContainsString('My Page Title', $html);
        $this->assertStringContainsString('Hello World', $html);
    }

    /**
     * Bug #2 variant: Shorthand section with quoted string value.
     */
    #[Test]
    public function bug2_section_shorthand_with_quoted_value(): void
    {
        $this->createView('layouts.expr', <<<'TEMPLATE'
        <title>@yield('title')</title>
        TEMPLATE);

        $this->createView('bug2b', <<<'TEMPLATE'
        @extends('layouts.expr')
        @section('title', 'Dashboard Page')
        TEMPLATE);

        $html = $this->renderer->render('bug2b');

        $this->assertStringContainsString('Dashboard Page', $html);
    }

    /**
     * Bug #3: @yield with default value should use the default when section is not defined.
     */
    #[Test]
    public function bug3_yield_with_default_value(): void
    {
        $this->createView('bug3_layout', <<<'TEMPLATE'
        <title>@yield('title', 'Default Title')</title>
        <body>@yield('content', 'No content')</body>
        TEMPLATE);

        $this->createView('bug3', <<<'TEMPLATE'
        @extends('bug3_layout')
        @section('content')
        <p>Actual content</p>
        @endsection
        TEMPLATE);

        $html = $this->renderer->render('bug3');

        // title should use default since it's not defined
        $this->assertStringContainsString('Default Title', $html);
        // content should use the actual section
        $this->assertStringContainsString('Actual content', $html);
    }

    /**
     * Bug #3 variant: @yield default with a PHP expression.
     */
    #[Test]
    public function bug3_yield_default_with_expression(): void
    {
        $this->createView('bug3b', <<<'TEMPLATE'
        <meta name="description" content="@yield('description', 'Welcome to ' . $appName)">
        TEMPLATE);

        $html = $this->renderer->render('bug3b', ['appName' => 'MLView']);

        $this->assertStringContainsString('Welcome to MLView', $html);
    }

    /**
     * Verify that existing block @section still works (no regression).
     */
    #[Test]
    public function existing_block_section_still_works(): void
    {
        $this->createView('layouts.block', <<<'TEMPLATE'
        <header>@yield('header')</header>
        <main>@yield('content')</main>
        TEMPLATE);

        $this->createView('block_test', <<<'TEMPLATE'
        @extends('layouts.block')
        @section('header')
        <h1>Welcome</h1>
        @endsection
        @section('content')
        <p>Page content</p>
        @endsection
        TEMPLATE);

        $html = $this->renderer->render('block_test');

        $this->assertStringContainsString('Welcome', $html);
        $this->assertStringContainsString('Page content', $html);
    }
}
