<?php

namespace MonkeysLegion\Template\Tests\Unit;

use MonkeysLegion\Template\Support\Escaper;
use PHPUnit\Framework\TestCase;

class EscaperTest extends TestCase
{
    public function testHtmlEscaping(): void
    {
        $input = '<script>alert("xss")</script>';
        $expected = '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;';
        $this->assertEquals($expected, Escaper::html($input));
    }

    public function testAttrEscaping(): void
    {
        $input = 'foo"bar';
        $expected = 'foo&quot;bar';
        $this->assertEquals($expected, Escaper::attr($input));
    }

    public function testJsEscaping(): void
    {
        $input = ['foo' => 'bar"baz'];
        // JSON encoded
        $encoded = Escaper::js($input);
        $this->assertStringContainsString('bar\u0022baz', $encoded);
    }

    public function testUrlEscaping(): void
    {
        $input = 'foo bar';
        $this->assertEquals('foo%20bar', Escaper::url($input));
    }

    public function testEscapeHelper(): void
    {
        $this->assertEquals('&lt;b&gt;', Escaper::escape('html', '<b>'));
        $this->assertEquals('<b>', Escaper::escape('raw', '<b>'));
    }

    public function testCheckStrictRawTriggersWarning(): void
    {
        $caught = false;
        set_error_handler(function ($errno, $errstr) use (&$caught) {
            if ($errno === E_USER_WARNING && str_contains($errstr, 'Security Warning: Unescaped output detected in Strict Mode')) {
                $caught = true;
            }
            return true; // suppress default handler
        });

        try {
            Escaper::checkStrictRaw('<script>');
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($caught, 'Expected E_USER_WARNING was not triggered.');
    }
}
