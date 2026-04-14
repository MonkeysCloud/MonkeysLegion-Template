<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Testing\TestView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TestView view testing assertions.
 */
final class TestViewTest extends TestCase
{
    #[Test]
    public function assertSee_passes(): void
    {
        $view = TestView::fromRendered('home', '<h1>Hello World</h1>');

        // Should not throw
        $view->assertSee('Hello World');
    }

    #[Test]
    public function assertSee_is_fluent(): void
    {
        $view = TestView::fromRendered('home', '<h1>Hello</h1><p>World</p>');

        $result = $view->assertSee('Hello')->assertSee('World');

        $this->assertSame($view, $result);
    }

    #[Test]
    public function assertDontSee_passes(): void
    {
        $view = TestView::fromRendered('home', '<h1>Hello</h1>');

        $view->assertDontSee('Goodbye');
    }

    #[Test]
    public function assertSeeInOrder_passes(): void
    {
        $view = TestView::fromRendered('page', '<h1>Title</h1><p>Content</p><footer>End</footer>');

        $view->assertSeeInOrder(['Title', 'Content', 'End']);
    }

    #[Test]
    public function assertSeeHtml_passes(): void
    {
        $view = TestView::fromRendered('page', '<div class="alert"><p>Error</p></div>');

        $view->assertSeeHtml('<p>Error</p>');
    }

    #[Test]
    public function assertDontSeeHtml_passes(): void
    {
        $view = TestView::fromRendered('page', '<div>Safe</div>');

        $view->assertDontSeeHtml('<script>');
    }

    #[Test]
    public function assertHasData_key_only(): void
    {
        $view = TestView::fromRendered('home', 'output', ['title' => 'Home']);

        $view->assertHasData('title');
    }

    #[Test]
    public function assertHasData_with_value(): void
    {
        $view = TestView::fromRendered('home', 'output', ['count' => 42]);

        $view->assertHasData('count', 42);
    }

    #[Test]
    public function assertViewIs_passes(): void
    {
        $view = TestView::fromRendered('dashboard', 'output');

        $view->assertViewIs('dashboard');
    }

    #[Test]
    public function getters(): void
    {
        $view = TestView::fromRendered('home', '<h1>Hi</h1>', ['key' => 'val']);

        $this->assertSame('home', $view->getName());
        $this->assertSame('<h1>Hi</h1>', $view->getOutput());
        $this->assertSame(['key' => 'val'], $view->getData());
    }

    #[Test]
    public function chained_assertions(): void
    {
        $view = TestView::fromRendered('page', '<h1>Title</h1><p>Body</p>', ['title' => 'Title']);

        $view->assertViewIs('page')
            ->assertSee('Title')
            ->assertSee('Body')
            ->assertDontSee('Missing')
            ->assertSeeHtml('<p>Body</p>')
            ->assertDontSeeHtml('<script>')
            ->assertHasData('title', 'Title')
            ->assertSeeInOrder(['Title', 'Body']);
    }
}
