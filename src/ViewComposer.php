<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use MonkeysLegion\Template\Contracts\ViewComposerInterface;

/**
 * Abstract base class for view composers.
 *
 * Provides a convenient base for building composers that inject
 * shared data into views. Extend this class and implement compose().
 *
 * Usage:
 *   #[Composer(views: ['layouts.*'])]
 *   class NavigationComposer extends ViewComposer
 *   {
 *       public function compose(ViewData $view): void
 *       {
 *           $view->with('navItems', $this->getNavItems());
 *       }
 *   }
 */
abstract class ViewComposer implements ViewComposerInterface
{
    /**
     * Compose data into the view.
     */
    abstract public function compose(ViewData $view): void;

    /**
     * Helper: share multiple key-value pairs at once.
     *
     * @param ViewData $view
     * @param array<string, mixed> $data
     */
    protected function shareMany(ViewData $view, array $data): void
    {
        foreach ($data as $key => $value) {
            $view->with($key, $value);
        }
    }
}
