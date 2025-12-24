<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
    }

    public function testItRemovesPropsDirectives(): void
    {
        $source = <<<'EOT'
@props(['title', 'active' => false])
<div class="test"></div>
EOT;
        $parsed = $this->parser->removePropsDirectives($source);

        $this->assertStringNotContainsString('@props', $parsed);
        $this->assertStringContainsString('<div class="test"></div>', $parsed);
    }

    public function testItExtractsComponentParams(): void
    {
        $source = "@props(['title' => 'Hello', 'count' => 5])";
        $params = $this->parser->extractComponentParams($source);

        $this->assertEquals(['title' => 'Hello', 'count' => 5], $params);
    }

    public function testItExtractsLegacyParamDirective(): void
    {
        $source = "@param(['type' => 'primary'])";
        $params = $this->parser->extractComponentParams($source);

        $this->assertEquals(['type' => 'primary'], $params);
    }

    public function testItParsesExtendsDirective(): void
    {
        $source = "@extends('layouts.app')";
        $parsed = $this->parser->parse($source);

        $this->assertStringContainsString("<?php \$__ml_extends = 'layouts/app'; ?>", $parsed);
    }

    public function testItParsesSectionAndYield(): void
    {
        $source = <<<'EOT'
@section('content')
    <p>Test</p>
@endsection
@yield('content')
EOT;
        $parsed = $this->parser->parse($source);

        $this->assertStringContainsString("\$__ml_sections['content'] = ob_get_clean();", $parsed);
        $this->assertStringContainsString("echo \$__ml_sections['content'] ?? '';", $parsed);
    }

    public function testItParsesIncludes(): void
    {
        $source = "@include('partials.header', ['foo' => 'bar'])";
        $parsed = $this->parser->parse($source);

        $this->assertStringContainsString("// Include template: partials.header", $parsed);
        $this->assertStringContainsString("\$__data_include = ['foo' => 'bar'];", $parsed);
        $this->assertStringContainsString("base_path('resources/views/partials/header.ml.php')", $parsed);
    }

    public function testItParsesComponents(): void
    {
        $source = '<x-ui.button type="submit" :disabled="true">Submit</x-ui.button>';
        $parsed = $this->parser->parse($source);

        $this->assertStringContainsString("/* Component: ui.button */", $parsed);
        $this->assertStringContainsString("\$this->resolveComponent('ui.button')", $parsed);
        // Check attributes parsing
        $this->assertStringContainsString("'type' => 'submit'", $parsed);
        // Check bound attribute
        $this->assertStringContainsString("'disabled' => true", $parsed);
    }

    public function testItParsesSlots(): void
    {
        $source = <<<'EOT'
<x-layout>
    <x-slot:header>Header Content</x-slot:header>
    Main Content
</x-layout>
EOT;
        $parsed = $this->parser->parse($source);

        $this->assertStringContainsString("\$__component_slots['header'] = function() {", $parsed);
        $this->assertStringContainsString("Header Content", $parsed);
    }
}
