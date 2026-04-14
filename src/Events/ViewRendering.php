<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Events;

/**
 * Dispatched before a view starts rendering.
 *
 * Listeners can modify data or inspect the view before it renders.
 */
final class ViewRendering
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $name,
        public array $data,
        public readonly string $path = '',
    ) {}
}
