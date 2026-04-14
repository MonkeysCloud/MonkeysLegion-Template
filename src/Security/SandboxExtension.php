<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Security;

use RuntimeException;

/**
 * Runtime security guard for sandbox enforcement.
 *
 * Wraps function/method calls in compiled PHP with runtime security checks.
 * Enforces SandboxPolicy constraints during template execution.
 */
final class SandboxExtension
{
    private SandboxPolicy $policy;

    private int $includeCount = 0;
    private int $loopIterationCount = 0;
    private bool $enabled = true;

    public function __construct(SandboxPolicy $policy)
    {
        $this->policy = $policy;
    }

    /**
     * Get the sandbox policy.
     */
    public function getPolicy(): SandboxPolicy
    {
        return $this->policy;
    }

    /**
     * Enable or disable the sandbox.
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Check if sandbox is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if a function call is allowed. Throws if denied.
     *
     * @throws RuntimeException
     */
    public function checkFunction(string $function): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!$this->policy->isFunctionAllowed($function)) {
            throw new RuntimeException(
                "Sandbox violation: function '{$function}' is not allowed.",
            );
        }
    }

    /**
     * Check if a class instantiation is allowed. Throws if denied.
     *
     * @throws RuntimeException
     */
    public function checkClass(string $class): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!$this->policy->isClassAllowed($class)) {
            throw new RuntimeException(
                "Sandbox violation: class '{$class}' is not allowed.",
            );
        }
    }

    /**
     * Check if a method call is allowed. Throws if denied.
     *
     * @throws RuntimeException
     */
    public function checkMethod(string $class, string $method): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!$this->policy->isMethodAllowed($class, $method)) {
            throw new RuntimeException(
                "Sandbox violation: method '{$class}::{$method}' is not allowed.",
            );
        }
    }

    /**
     * Check if a tag/directive is allowed. Throws if denied.
     *
     * @throws RuntimeException
     */
    public function checkTag(string $tag): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!$this->policy->isTagAllowed($tag)) {
            throw new RuntimeException(
                "Sandbox violation: tag '@{$tag}' is not allowed.",
            );
        }
    }

    /**
     * Track and enforce include depth limits.
     *
     * @throws RuntimeException
     */
    public function trackInclude(): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->includeCount++;
        if ($this->includeCount > $this->policy->getMaxIncludes()) {
            throw new RuntimeException(
                "Sandbox violation: maximum include depth ({$this->policy->getMaxIncludes()}) exceeded.",
            );
        }
    }

    /**
     * Track and enforce loop iteration limits.
     *
     * @throws RuntimeException
     */
    public function trackLoopIteration(): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->loopIterationCount++;
        if ($this->loopIterationCount > $this->policy->getMaxLoopIterations()) {
            throw new RuntimeException(
                "Sandbox violation: maximum loop iterations ({$this->policy->getMaxLoopIterations()}) exceeded.",
            );
        }
    }

    /**
     * Reset counters (call between renders).
     */
    public function reset(): void
    {
        $this->includeCount = 0;
        $this->loopIterationCount = 0;
    }

    /**
     * Get current include count.
     */
    public function getIncludeCount(): int
    {
        return $this->includeCount;
    }

    /**
     * Get current loop iteration count.
     */
    public function getLoopIterationCount(): int
    {
        return $this->loopIterationCount;
    }
}
