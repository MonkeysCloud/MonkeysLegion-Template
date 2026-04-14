<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Attributes;

use Attribute;

/**
 * Bind a view composer to one or more views.
 *
 * Usage:
 *   #[Composer(views: ['layouts.*', 'dashboard'])]
 *   class NavigationComposer implements ViewComposerInterface { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Composer
{
    /** @var list<string> */
    public readonly array $views;

    /**
     * @param list<string> $views
     */
    public function __construct(
        array $views,
    ) {
        $this->views = $views;
    }
}
