<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Support;

/**
 * Loop variable proxy for @foreach.
 * Provides access to loop state properties like $loop->index, $loop->first, etc.
 */
class Loop
{
    /** @var int The zero-based index of the current loop iteration */
    public int $index;

    /** @var int The one-based iteration of the current loop */
    public int $iteration;

    /** @var int The number of items in the loop */
    public int $count;

    /** @var int The number of iterations remaining */
    public int $remaining;

    /** @var bool Whether this is the first iteration */
    public bool $first;

    /** @var bool Whether this is the last iteration */
    public bool $last;

    /** @var bool Whether this is an even iteration */
    public bool $even;

    /** @var bool Whether this is an odd iteration */
    public bool $odd;

    /** @var int The nesting level of the current loop */
    public int $depth;

    /** @var Loop|null The parent loop variable, if any */
    public ?Loop $parent;

    /**
     * @param int       $count  Total items
     * @param int       $index  Current index (0-based)
     * @param int       $depth  Loop depth
     * @param Loop|null $parent Parent loop
     */
    public function __construct(int $count, int $index, int $depth, ?Loop $parent = null)
    {
        $this->index = $index;
        $this->iteration = $index + 1;
        $this->count = $count;
        $this->remaining = $count - $this->iteration;

        $this->first = ($index === 0);
        $this->last = ($this->iteration === $count);
        $this->even = ($this->iteration % 2 === 0);
        $this->odd = !$this->even;

        $this->depth = $depth;
        $this->parent = $parent;
    }

    /**
     * Helper to create/update loop state
     *
     * @param mixed     $items  Iterable items
     * @param Loop|null $parent Parent loop
     * @return Loop
     */
    public static function start(mixed $items, ?Loop $parent = null): Loop
    {
        $count = is_countable($items) ? count($items) : 0;

        // If it was a traversable that we just counted, we might have exhausted it if it was a generator.
        // However, in the template, we usually operate on arrays or collections that can be recounted.
        // For simple Generators, $loop->count might be expensive or impossible without consuming.
        // We accept that limitation or expect user to pass countable.

        $depth = ($parent ? $parent->depth + 1 : 1);

        return new self($count, 0, $depth, $parent);
    }

    /**
     * Advance the loop to the next iteration
     */
    public function tick(): void
    {
        // Immutable-ish pattern or mutable update?
        // Mutable is faster for tight loops.
        $this->index++;
        $this->iteration++;
        $this->remaining--;

        $this->first = ($this->index === 0);
        $this->last = ($this->iteration === $this->count);
        $this->even = ($this->iteration % 2 === 0);
        $this->odd = !$this->even;
    }
}
