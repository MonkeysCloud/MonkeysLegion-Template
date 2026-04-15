<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\ViewComposer;
use MonkeysLegion\Template\ViewData;
use MonkeysLegion\Template\Contracts\ViewComposerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ViewComposer base class.
 */
final class ViewComposerBaseTest extends TestCase
{
    #[Test]
    public function implements_interface(): void
    {
        $composer = new class extends ViewComposer {
            public function compose(ViewData $view): void
            {
                $view->with('key', 'value');
            }
        };

        $this->assertInstanceOf(ViewComposerInterface::class, $composer);
    }

    #[Test]
    public function compose_injects_data(): void
    {
        $composer = new class extends ViewComposer {
            public function compose(ViewData $view): void
            {
                $view->with('title', 'Dashboard');
            }
        };

        $viewData = ViewData::make('home');
        $composer->compose($viewData);

        $data = $viewData->getData();
        $this->assertSame('Dashboard', $data['title']);
    }

    #[Test]
    public function shareMany_helper(): void
    {
        $composer = new class extends ViewComposer {
            public function compose(ViewData $view): void
            {
                $this->shareMany($view, [
                    'nav' => ['Home', 'About'],
                    'year' => 2026,
                ]);
            }
        };

        $viewData = ViewData::make('page');
        $composer->compose($viewData);

        $data = $viewData->getData();
        $this->assertSame(['Home', 'About'], $data['nav']);
        $this->assertSame(2026, $data['year']);
    }

    #[Test]
    public function multiple_composers_chain(): void
    {
        $nav = new class extends ViewComposer {
            public function compose(ViewData $view): void
            {
                $view->with('nav', ['Home', 'Products']);
            }
        };

        $auth = new class extends ViewComposer {
            public function compose(ViewData $view): void
            {
                $view->with('user', 'Jorge');
            }
        };

        $viewData = ViewData::make('dashboard');
        $nav->compose($viewData);
        $auth->compose($viewData);

        $data = $viewData->getData();
        $this->assertSame(['Home', 'Products'], $data['nav']);
        $this->assertSame('Jorge', $data['user']);
    }
}
