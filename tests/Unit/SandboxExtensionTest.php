<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Security\SandboxExtension;
use MonkeysLegion\Template\Security\SandboxPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for SandboxExtension — runtime security guard.
 */
final class SandboxExtensionTest extends TestCase
{
    private SandboxExtension $sandbox;

    protected function setUp(): void
    {
        $policy = new SandboxPolicy();
        $policy->maxIncludes(3)->maxLoopIterations(5);
        $this->sandbox = new SandboxExtension($policy);
    }

    #[Test]
    public function checkFunction_allows_safe(): void
    {
        $this->sandbox->checkFunction('date');
        $this->addToAssertionCount(1); // No exception = pass
    }

    #[Test]
    public function checkFunction_blocks_denied(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('function \'exec\' is not allowed');

        $this->sandbox->checkFunction('exec');
    }

    #[Test]
    public function checkClass_blocks_denied(): void
    {
        $policy = new SandboxPolicy();
        $policy->denyClass('ReflectionClass');
        $sandbox = new SandboxExtension($policy);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('class \'ReflectionClass\' is not allowed');

        $sandbox->checkClass('ReflectionClass');
    }

    #[Test]
    public function checkMethod_blocks_denied(): void
    {
        $policy = new SandboxPolicy();
        $policy->denyMethod('User', 'delete');
        $sandbox = new SandboxExtension($policy);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('method \'User::delete\' is not allowed');

        $sandbox->checkMethod('User', 'delete');
    }

    #[Test]
    public function checkTag_blocks_denied(): void
    {
        $policy = new SandboxPolicy();
        $policy->denyTag('php');
        $sandbox = new SandboxExtension($policy);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("tag '@php' is not allowed");

        $sandbox->checkTag('php');
    }

    #[Test]
    public function trackInclude_enforces_limit(): void
    {
        // maxIncludes = 3
        $this->sandbox->trackInclude(); // 1
        $this->sandbox->trackInclude(); // 2
        $this->sandbox->trackInclude(); // 3

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('maximum include depth');

        $this->sandbox->trackInclude(); // 4 — should fail
    }

    #[Test]
    public function trackLoopIteration_enforces_limit(): void
    {
        // maxLoopIterations = 5
        for ($i = 0; $i < 5; $i++) {
            $this->sandbox->trackLoopIteration();
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('maximum loop iterations');

        $this->sandbox->trackLoopIteration(); // 6 — should fail
    }

    #[Test]
    public function reset_clears_counters(): void
    {
        $this->sandbox->trackInclude();
        $this->sandbox->trackInclude();
        $this->sandbox->trackLoopIteration();

        $this->sandbox->reset();

        $this->assertSame(0, $this->sandbox->getIncludeCount());
        $this->assertSame(0, $this->sandbox->getLoopIterationCount());
    }

    #[Test]
    public function disabled_sandbox_allows_everything(): void
    {
        $this->sandbox->setEnabled(false);

        // None of these should throw
        $this->sandbox->checkFunction('exec');
        $this->sandbox->trackInclude();
        $this->sandbox->trackInclude();
        $this->sandbox->trackInclude();
        $this->sandbox->trackInclude(); // Would normally exceed limit

        $this->assertFalse($this->sandbox->isEnabled());
    }

    #[Test]
    public function getPolicy_returns_policy(): void
    {
        $policy = $this->sandbox->getPolicy();

        $this->assertInstanceOf(SandboxPolicy::class, $policy);
        $this->assertSame(3, $policy->getMaxIncludes());
    }

    #[Test]
    public function counters_increment(): void
    {
        $this->sandbox->trackInclude();
        $this->sandbox->trackLoopIteration();
        $this->sandbox->trackLoopIteration();

        $this->assertSame(1, $this->sandbox->getIncludeCount());
        $this->assertSame(2, $this->sandbox->getLoopIterationCount());
    }
}
