<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use MonkeysLegion\Template\Support\SlotCollection;
use PHPUnit\Framework\TestCase;

class SlotCollectionTest extends TestCase
{
    public function testItManagesSlots(): void
    {
        $slots = SlotCollection::fromArray([
            'header' => fn() => 'Header Content',
            '__default' => 'Default Content'
        ]);

        $this->assertTrue($slots->has('header'));
        $this->assertFalse($slots->has('footer'));

        // Test getter
        /** @phpstan-ignore property.notFound */
        $this->assertEquals('Header Content', (string)$slots->header);
    }

    public function testItRendersDefaultContentAsString(): void
    {
        $slots = SlotCollection::fromArray([
            '__default' => 'Main Content'
        ]);

        $this->assertEquals('Main Content', (string)$slots);
    }

    public function testItHandlesNonExistentSlotsGracefully(): void
    {
        $slots = new SlotCollection([]);

        // Accessing non-existent property should return empty string instance effectively
        /** @phpstan-ignore property.notFound */
        $this->assertEmpty((string)$slots->missing);
    }
}
