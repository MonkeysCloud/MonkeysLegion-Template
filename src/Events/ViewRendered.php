<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Events;

/**
 * Dispatched after a view finishes rendering.
 *
 * Listeners can post-process output (e.g. add telemetry, minify HTML).
 */
final class ViewRendered
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $name,
        public readonly array $data,
        public string $output,
    ) {}
}
