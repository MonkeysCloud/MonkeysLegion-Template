<?php

namespace Tests\Integration;

use Tests\TestCase;

class StrictEscapingTest extends TestCase
{
    public function testEscapedEchoUsesEscaper()
    {
        $this->createView('test', 'Hello {{ "<script>" }}');
        $output = $this->renderer->render('test');
        $this->assertEquals('Hello &lt;script&gt;', $output);
    }

    public function testAttributeEscaping()
    {
        // Compiler::compileAttributeExpressions uses Escaper::attr
        // Syntax: <div class="{{ $val }}">
        $this->createView('attr', '<div data-test="{{ "foo\"bar" }}"></div>');
        $output = $this->renderer->render('attr');
        // Escaper::attr uses htmlspecialchars with ENT_QUOTES
        $this->assertStringContainsString('data-test="foo&quot;bar"', $output);
    }

    public function testRawEchoInStrictModeTriggersWarning()
    {
        $this->compiler->setStrictMode(true);
        $this->createView('strict_raw', '{!! "<script>" !!}');

        $caught = false;
        set_error_handler(function ($errno, $errstr) use (&$caught) {
            if ($errno === E_USER_WARNING && str_contains($errstr, 'Security Warning')) {
                $caught = true;
            }
            return true;
        });

        try {
            $this->renderer->render('strict_raw');
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($caught, 'Strict mode warning was not triggered for {!! !!}');
    }

    public function testEscapeDirective()
    {
        // @escape('js', $val)
        $this->createView('escape_js', '<script>var x = @escape(\'js\', ["a" => "b"]);</script>');
        $output = $this->renderer->render('escape_js');
        $this->assertStringContainsString('{"a":"b"}', $output);
    }
}
