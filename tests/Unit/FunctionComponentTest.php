<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\FunctionComponent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for lightweight function components.
 */
final class FunctionComponentTest extends TestCase
{
    protected function setUp(): void
    {
        FunctionComponent::clear();
    }

    protected function tearDown(): void
    {
        FunctionComponent::clear();
    }

    #[Test]
    public function registers_and_checks_component(): void
    {
        FunctionComponent::register('badge', fn(string $text) => "<span>{$text}</span>");

        $this->assertTrue(FunctionComponent::has('badge'));
        $this->assertFalse(FunctionComponent::has('nonexistent'));
    }

    #[Test]
    public function renders_simple_component(): void
    {
        FunctionComponent::register('badge', fn(string $text) => "<span>{$text}</span>");

        $result = FunctionComponent::render('badge', ['text' => 'Active']);

        $this->assertSame('<span>Active</span>', $result);
    }

    #[Test]
    public function renders_with_default_params(): void
    {
        FunctionComponent::register('badge', function (string $text, string $color = 'blue'): string {
            return "<span class=\"badge bg-{$color}\">{$text}</span>";
        });

        $result = FunctionComponent::render('badge', ['text' => 'New']);

        $this->assertSame('<span class="badge bg-blue">New</span>', $result);
    }

    #[Test]
    public function renders_with_override_params(): void
    {
        FunctionComponent::register('badge', function (string $text, string $color = 'blue'): string {
            return "<span class=\"badge bg-{$color}\">{$text}</span>";
        });

        $result = FunctionComponent::render('badge', ['text' => 'Alert', 'color' => 'red']);

        $this->assertSame('<span class="badge bg-red">Alert</span>', $result);
    }

    #[Test]
    public function throws_for_unregistered_component(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Function component [unknown] is not registered');

        FunctionComponent::render('unknown');
    }

    #[Test]
    public function list_all_registered(): void
    {
        FunctionComponent::register('badge', fn() => '');
        FunctionComponent::register('icon', fn() => '');

        $all = FunctionComponent::all();

        $this->assertContains('badge', $all);
        $this->assertContains('icon', $all);
        $this->assertCount(2, $all);
    }

    #[Test]
    public function unregister_removes_component(): void
    {
        FunctionComponent::register('badge', fn() => '');
        $this->assertTrue(FunctionComponent::has('badge'));

        FunctionComponent::unregister('badge');
        $this->assertFalse(FunctionComponent::has('badge'));
    }

    #[Test]
    public function clear_removes_all(): void
    {
        FunctionComponent::register('a', fn() => '');
        FunctionComponent::register('b', fn() => '');

        FunctionComponent::clear();

        $this->assertEmpty(FunctionComponent::all());
    }
}
