<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use MonkeysLegion\Template\Support\AttributeBag;
use PHPUnit\Framework\TestCase;

class AttributeBagTest extends TestCase
{
    public function testItRendersAttributes(): void
    {
        $bag = new AttributeBag(['class' => 'btn', 'disabled' => true, 'id' => 'my-btn']);
        $html = (string) $bag;

        $this->assertStringContainsString('class="btn"', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('id="my-btn"', $html);
    }

    public function testItMergesAttributes(): void
    {
        $bag = new AttributeBag(['class' => 'p-4', 'type' => 'text']);
        $merged = $bag->merge(['class' => 'text-red', 'required' => true]);

        // Classes should concatenate
        $str = (string) $merged;
        $this->assertStringContainsString('p-4', $str);
        $this->assertStringContainsString('text-red', $str);
        // New attr should be present
        $this->assertStringContainsString('required', (string) $merged);
        // Existing non-class attr should remain
        $this->assertStringContainsString('type="text"', (string) $merged);
    }

    public function testConditionalClasses(): void
    {
        $classes = AttributeBag::conditional([
            'btn',
            'active' => true,
            'hidden' => false,
        ]);

        $this->assertEquals('btn active', $classes);
    }

    public function testGetAttribute(): void
    {
        $bag = new AttributeBag(['foo' => 'bar']);
        $this->assertEquals('bar', $bag->get('foo'));
        $this->assertNull($bag->get('baz'));
    }
}
