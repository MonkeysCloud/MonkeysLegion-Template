<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Security;

/**
 * Configurable security policy for template sandboxing.
 *
 * Controls what functions, classes, methods, and properties templates
 * are allowed to use. Inspired by Twig's sandbox extension and
 * Liquid's strict sandboxing (Shopify merchant templates).
 *
 * Usage:
 *   $policy = new SandboxPolicy();
 *   $policy
 *       ->allowFunction('date', 'strtoupper', 'count')
 *       ->denyFunction('exec', 'passthru', 'system', 'shell_exec', 'eval')
 *       ->allowClass(Carbon::class)
 *       ->denyMethod('User', 'delete')
 *       ->maxIncludes(10)
 *       ->maxLoopIterations(1000);
 */
final class SandboxPolicy
{
    /** @var array<int, string> */
    private array $allowedFunctions = [];

    /** @var array<int, string> */
    private array $deniedFunctions = [
        'exec', 'passthru', 'system', 'shell_exec', 'eval',
        'popen', 'proc_open', 'pcntl_exec', 'dl',
    ];

    /** @var array<int, string> */
    private array $allowedClasses = [];

    /** @var array<int, string> */
    private array $deniedClasses = [];

    /** @var list<string> Format: "ClassName::methodName" */
    private array $deniedMethods = [];

    /** @var array<int, string> */
    private array $allowedTags = [];

    /** @var array<int, string> */
    private array $deniedTags = [];

    private int $maxIncludes = 50;
    private int $maxLoopIterations = 10000;

    /**
     * Allow specific functions.
     */
    public function allowFunction(string ...$functions): self
    {
        $this->allowedFunctions = array_values(array_merge($this->allowedFunctions, $functions));
        return $this;
    }

    /**
     * Deny specific functions.
     */
    public function denyFunction(string ...$functions): self
    {
        $this->deniedFunctions = array_values(array_merge($this->deniedFunctions, $functions));
        return $this;
    }

    /**
     * Allow specific classes.
     */
    public function allowClass(string ...$classes): self
    {
        $this->allowedClasses = array_values(array_merge($this->allowedClasses, $classes));
        return $this;
    }

    /**
     * Deny specific classes.
     */
    public function denyClass(string ...$classes): self
    {
        $this->deniedClasses = array_values(array_merge($this->deniedClasses, $classes));
        return $this;
    }

    /**
     * Deny a specific method on a class.
     */
    public function denyMethod(string $class, string $method): self
    {
        $this->deniedMethods[] = $class . '::' . $method;
        return $this;
    }

    /**
     * Allow specific template tags/directives.
     */
    public function allowTag(string ...$tags): self
    {
        $this->allowedTags = array_values(array_merge($this->allowedTags, $tags));
        return $this;
    }

    /**
     * Deny specific template tags/directives.
     */
    public function denyTag(string ...$tags): self
    {
        $this->deniedTags = array_values(array_merge($this->deniedTags, $tags));
        return $this;
    }

    /**
     * Set maximum number of includes per template render.
     */
    public function maxIncludes(int $max): self
    {
        $this->maxIncludes = $max;
        return $this;
    }

    /**
     * Set maximum loop iterations per template render.
     */
    public function maxLoopIterations(int $max): self
    {
        $this->maxLoopIterations = $max;
        return $this;
    }

    /**
     * Check if a function is allowed.
     */
    public function isFunctionAllowed(string $function): bool
    {
        if (in_array($function, $this->deniedFunctions, true)) {
            return false;
        }

        if (!empty($this->allowedFunctions)) {
            return in_array($function, $this->allowedFunctions, true);
        }

        return true;
    }

    /**
     * Check if a class is allowed.
     */
    public function isClassAllowed(string $class): bool
    {
        if (in_array($class, $this->deniedClasses, true)) {
            return false;
        }

        if (!empty($this->allowedClasses)) {
            return in_array($class, $this->allowedClasses, true);
        }

        return true;
    }

    /**
     * Check if a method call is allowed.
     */
    public function isMethodAllowed(string $class, string $method): bool
    {
        return !in_array($class . '::' . $method, $this->deniedMethods, true);
    }

    /**
     * Check if a tag/directive is allowed.
     */
    public function isTagAllowed(string $tag): bool
    {
        if (in_array($tag, $this->deniedTags, true)) {
            return false;
        }

        if (!empty($this->allowedTags)) {
            return in_array($tag, $this->allowedTags, true);
        }

        return true;
    }

    /**
     * Get maximum include depth.
     */
    public function getMaxIncludes(): int
    {
        return $this->maxIncludes;
    }

    /**
     * Get maximum loop iterations.
     */
    public function getMaxLoopIterations(): int
    {
        return $this->maxLoopIterations;
    }

    /**
     * Get all denied functions.
     *
     * @return array<int, string>
     */
    public function getDeniedFunctions(): array
    {
        return $this->deniedFunctions;
    }

    /**
     * Get all allowed functions.
     *
     * @return array<int, string>
     */
    public function getAllowedFunctions(): array
    {
        return $this->allowedFunctions;
    }
}
