<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Contracts\ViewComposerInterface;
use MonkeysLegion\Template\ViewData;
use MonkeysLegion\Template\Attributes\Composer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Stub composer
class StubNavComposer implements ViewComposerInterface
{
    public function compose(ViewData $view): void
    {
        $view->with('navItems', ['Home', 'About', 'Contact']);
    }
}

#[Composer(views: ['layouts.*', 'dashboard'])]
class StubAnnotatedComposer implements ViewComposerInterface
{
    public function compose(ViewData $view): void
    {
        $view->with('appName', 'MonkeysCloud');
    }
}

/**
 * Tests for view composers and shared data injection.
 */
final class ViewComposerTest extends TestCase
{
    #[Test]
    public function composer_interface_is_callable(): void
    {
        $composer = new StubNavComposer();
        $view = new ViewData('test');

        $composer->compose($view);

        $this->assertSame(['Home', 'About', 'Contact'], $view->get('navItems'));
    }

    #[Test]
    public function composer_attribute_stores_views(): void
    {
        $reflection = new \ReflectionClass(StubAnnotatedComposer::class);
        $attrs = $reflection->getAttributes(Composer::class);

        $this->assertCount(1, $attrs);

        $composer = $attrs[0]->newInstance();
        $this->assertSame(['layouts.*', 'dashboard'], $composer->views);
    }

    #[Test]
    public function composer_modifies_view_data(): void
    {
        $composer = new StubAnnotatedComposer();
        $view = new ViewData('layouts.app', ['title' => 'Page']);

        $composer->compose($view);

        $this->assertSame('MonkeysCloud', $view->get('appName'));
        // Original data should still be there
        $this->assertSame('Page', $view->get('title'));
    }

    #[Test]
    public function multiple_composers_stack_data(): void
    {
        $view = new ViewData('dashboard');

        $nav = new StubNavComposer();
        $nav->compose($view);

        $app = new StubAnnotatedComposer();
        $app->compose($view);

        $this->assertTrue($view->has('navItems'));
        $this->assertTrue($view->has('appName'));
    }

    #[Test]
    public function callable_composer(): void
    {
        $view = new ViewData('page');

        $composer = function (ViewData $v): void {
            $v->with('year', 2026);
        };
        $composer($view);

        $this->assertSame(2026, $view->get('year'));
    }
}
