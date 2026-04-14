<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\ViewData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ViewData — fluent view data object.
 */
final class ViewDataTest extends TestCase
{
    #[Test]
    public function creates_with_name_and_data(): void
    {
        $view = new ViewData('dashboard', ['title' => 'Dashboard']);

        $this->assertSame('dashboard', $view->getName());
        $this->assertSame('Dashboard', $view->get('title'));
    }

    #[Test]
    public function with_adds_data(): void
    {
        $view = new ViewData('page');
        $result = $view->with('key', 'value');

        $this->assertSame($view, $result); // fluent
        $this->assertSame('value', $view->get('key'));
    }

    #[Test]
    public function withData_merges_array(): void
    {
        $view = new ViewData('page', ['a' => 1]);
        $view->withData(['b' => 2, 'c' => 3]);

        $this->assertSame(1, $view->get('a'));
        $this->assertSame(2, $view->get('b'));
        $this->assertSame(3, $view->get('c'));
    }

    #[Test]
    public function has_checks_key(): void
    {
        $view = new ViewData('page', ['name' => 'Test']);

        $this->assertTrue($view->has('name'));
        $this->assertFalse($view->has('missing'));
    }

    #[Test]
    public function get_returns_default(): void
    {
        $view = new ViewData('page');

        $this->assertNull($view->get('missing'));
        $this->assertSame('fallback', $view->get('missing', 'fallback'));
    }

    #[Test]
    public function getData_returns_all(): void
    {
        $data = ['a' => 1, 'b' => 2];
        $view = new ViewData('page', $data);

        $this->assertSame($data, $view->getData());
    }

    #[Test]
    public function fluent_chaining(): void
    {
        $view = (new ViewData('page'))
            ->with('title', 'Page')
            ->with('subtitle', 'Sub')
            ->withData(['extra' => true]);

        $this->assertSame('Page', $view->get('title'));
        $this->assertSame('Sub', $view->get('subtitle'));
        $this->assertTrue($view->get('extra'));
    }

    #[Test]
    public function toString_without_renderer_returns_empty(): void
    {
        $view = new ViewData('page');

        // Without renderer, __toString returns ''
        $result = (string) $view;
        $this->assertSame('', $result);
    }

    #[Test]
    public function render_without_renderer_throws(): void
    {
        $view = new ViewData('page');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot render ViewData without a Renderer');

        $view->render();
    }

    #[Test]
    public function with_overwrites_existing_key(): void
    {
        $view = new ViewData('page', ['name' => 'Old']);
        $view->with('name', 'New');

        $this->assertSame('New', $view->get('name'));
    }
}
