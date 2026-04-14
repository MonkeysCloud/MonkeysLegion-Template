<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Attributes;

use Attribute;

/**
 * Register a class as a named view component.
 *
 * Usage:
 *   #[ViewComponent(name: 'alert')]
 *   class Alert extends Component { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ViewComponent
{
    public function __construct(
        public readonly string $name,
    ) {}
}
