<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Attributes;

use Attribute;

/**
 * Register a static method as a lightweight function component.
 *
 * Usage:
 *   #[FunctionComponent('badge')]
 *   public static function badge(string $text, string $color = 'blue'): string { ... }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class FunctionComponent
{
    public function __construct(
        public readonly string $name,
    ) {}
}
