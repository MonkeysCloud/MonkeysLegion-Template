<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Support;

use Closure;
use RuntimeException;

class DirectiveRegistry
{
    /** @var array<string, callable> */
    private array $directives = [];

    /** @var array<string, callable> */
    private array $filters = [];

    public function addDirective(string $name, callable $handler): void
    {
        $this->directives[$name] = $handler;
    }

    public function addFilter(string $name, callable $handler): void
    {
        $this->filters[$name] = $handler;
    }

    /**
     * @return array<string, callable>
     */
    public function getDirectives(): array
    {
        return $this->directives;
    }

    /**
     * @return array<string, callable>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    public function hasDirective(string $name): bool
    {
        return isset($this->directives[$name]);
    }

    public function hasFilter(string $name): bool
    {
        return isset($this->filters[$name]);
    }

    public function callDirective(string $name, mixed ...$args): string
    {
        if (!isset($this->directives[$name])) {
            throw new RuntimeException("Directive [{$name}] not found.");
        }
        return (string)call_user_func($this->directives[$name], ...$args);
    }
}
