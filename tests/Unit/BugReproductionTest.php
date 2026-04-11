<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\TestCase;

class BugReproductionTest extends TestCase
{
    private Compiler $compiler;
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
        $this->compiler = new Compiler($this->parser);
    }

    /**
     * Issue 1: Directive Parser Fails with Nested Parentheses
     */
    public function testNestedParenthesesInStyleDirective(): void
    {
        $source = '<span @style(["color: red" => ($count ?? 0) > 0])></span>';
        $compiled = $this->compiler->compile($source, 'test.ml.php');

        // The expected behavior is that the entire expression inside @style(...) is captured.
        // The bug causes it to stop at the first ')', which is in ($count ?? 0).
        // So the captured part will be '["color: red" => ($count ?? 0'
        // and the compiled output will be broken PHP.

        // We assert that the compiled output contains the full expression.
        $this->assertStringContainsString('foreach (["color: red" => ($count ?? 0) > 0] as', $compiled);
    }

    /**
     * Issue 1: Directive Parser Fails with Nested Parentheses (Class)
     */
    public function testNestedParenthesesInClassDirective(): void
    {
        $source = '<div @class(["bg-red-500" => (bool)($error ?? false)])></div>';
        $compiled = $this->compiler->compile($source, 'test.ml.php');

        $this->assertStringContainsString('conditional(["bg-red-500" => (bool)($error ?? false)])', $compiled);
    }

    /**
     * Issue 2: Unsupported Shorthand @section Syntax
     */
    public function testShorthandSection(): void
    {
        $source = "@section('title', 'Page Title')";
        $parsed = $this->parser->parse($source);

        // Current implementation will not match this and leave it as is.
        // We want it to be parsed into a section definition.
        $this->assertStringContainsString("\$__ml_sections['title'] = 'Page Title';", $parsed);
    }

    /**
     * Issue 3: @yield Does Not Support Default Values
     */
    public function testYieldDefaultValue(): void
    {
        $source = "@yield('title', 'Default Title')";
        $parsed = $this->parser->parse($source);

        // Current implementation will not match this and leave it as is.
        // We want it to be parsed into: echo $__ml_sections['title'] ?? 'Default Title';
        $this->assertStringContainsString("echo \$__ml_sections['title'] ?? 'Default Title';", $parsed);
    }
}
