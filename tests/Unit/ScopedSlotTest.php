<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Support\SlotCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for scoped slots with data back-passing (Vue/Svelte-style slot props).
 */
final class ScopedSlotTest extends TestCase
{
    #[Test]
    public function scoped_slot_passes_data_to_callable(): void
    {
        $slots = new SlotCollection([
            'row' => function (array $data): string {
                return "<td>{$data['name']}</td><td>{$data['email']}</td>";
            },
        ]);

        $result = $slots->get('row', '', ['name' => 'Alice', 'email' => 'alice@example.com']);

        $this->assertSame('<td>Alice</td><td>alice@example.com</td>', $result);
    }

    #[Test]
    public function scoped_slot_with_empty_data(): void
    {
        $slots = new SlotCollection([
            'cell' => function (array $data): string {
                return '<td>' . ($data['value'] ?? 'N/A') . '</td>';
            },
        ]);

        $result = $slots->get('cell', '', []);

        $this->assertSame('<td>N/A</td>', $result);
    }

    #[Test]
    public function non_callable_slot_ignores_data(): void
    {
        $slots = new SlotCollection([
            'header' => '<h1>Title</h1>',
        ]);

        // Static string slots don't use data
        $result = $slots->get('header', '', ['unused' => 'value']);

        $this->assertSame('<h1>Title</h1>', $result);
    }

    #[Test]
    public function scoped_slot_via_magic_getter(): void
    {
        $slots = new SlotCollection([
            'item' => function (array $data): string {
                return 'Item: ' . ($data['name'] ?? 'unknown');
            },
        ]);

        // Via get() without data — slot closure receives empty array
        $result = $slots->get('item');
        $this->assertSame('Item: unknown', $result);
    }

    #[Test]
    public function scoped_slot_with_multiple_items(): void
    {
        $slots = new SlotCollection([
            'row' => function (array $data): string {
                return "<tr><td>{$data['id']}</td><td>{$data['name']}</td></tr>";
            },
        ]);

        $items = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];

        $output = '';
        foreach ($items as $item) {
            $output .= $slots->get('row', '', $item);
        }

        $this->assertStringContainsString('<td>1</td><td>Alice</td>', $output);
        $this->assertStringContainsString('<td>2</td><td>Bob</td>', $output);
    }

    #[Test]
    public function default_slot_unchanged(): void
    {
        $slots = new SlotCollection([], 'Default Content');

        $this->assertSame('Default Content', $slots->getDefault());
    }

    #[Test]
    public function missing_scoped_slot_returns_default(): void
    {
        $slots = new SlotCollection([]);

        $result = $slots->get('missing', 'Fallback', ['data' => 'value']);

        $this->assertSame('Fallback', $result);
    }
}
