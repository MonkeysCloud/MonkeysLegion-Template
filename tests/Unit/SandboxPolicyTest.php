<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Security\SandboxPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SandboxPolicy — template security configuration.
 */
final class SandboxPolicyTest extends TestCase
{
    #[Test]
    public function default_denies_dangerous_functions(): void
    {
        $policy = new SandboxPolicy();

        $this->assertFalse($policy->isFunctionAllowed('exec'));
        $this->assertFalse($policy->isFunctionAllowed('system'));
        $this->assertFalse($policy->isFunctionAllowed('passthru'));
        $this->assertFalse($policy->isFunctionAllowed('shell_exec'));
        $this->assertFalse($policy->isFunctionAllowed('eval'));
    }

    #[Test]
    public function allows_safe_functions_by_default(): void
    {
        $policy = new SandboxPolicy();

        // Without explicit allow-list, non-denied functions are allowed
        $this->assertTrue($policy->isFunctionAllowed('date'));
        $this->assertTrue($policy->isFunctionAllowed('strtoupper'));
        $this->assertTrue($policy->isFunctionAllowed('count'));
    }

    #[Test]
    public function explicit_allowlist_restricts(): void
    {
        $policy = new SandboxPolicy();
        $policy->allowFunction('date', 'strtoupper');

        $this->assertTrue($policy->isFunctionAllowed('date'));
        $this->assertTrue($policy->isFunctionAllowed('strtoupper'));
        $this->assertFalse($policy->isFunctionAllowed('file_get_contents'));
    }

    #[Test]
    public function deny_overrides_allow(): void
    {
        $policy = new SandboxPolicy();
        $policy->allowFunction('exec'); // Try to allow dangerous
        $policy->denyFunction('exec');  // But deny wins

        $this->assertFalse($policy->isFunctionAllowed('exec'));
    }

    #[Test]
    public function class_allow_and_deny(): void
    {
        $policy = new SandboxPolicy();
        $policy->allowClass('Carbon');
        $policy->denyClass('ReflectionClass');

        $this->assertTrue($policy->isClassAllowed('Carbon'));
        $this->assertFalse($policy->isClassAllowed('ReflectionClass'));
    }

    #[Test]
    public function method_deny(): void
    {
        $policy = new SandboxPolicy();
        $policy->denyMethod('User', 'delete');

        $this->assertFalse($policy->isMethodAllowed('User', 'delete'));
        $this->assertTrue($policy->isMethodAllowed('User', 'getName'));
    }

    #[Test]
    public function tag_allow_and_deny(): void
    {
        $policy = new SandboxPolicy();
        $policy->allowTag('if', 'foreach', 'include');
        $policy->denyTag('php');

        $this->assertTrue($policy->isTagAllowed('if'));
        $this->assertFalse($policy->isTagAllowed('php'));
        $this->assertFalse($policy->isTagAllowed('raw'));
    }

    #[Test]
    public function max_includes(): void
    {
        $policy = new SandboxPolicy();
        $policy->maxIncludes(5);

        $this->assertSame(5, $policy->getMaxIncludes());
    }

    #[Test]
    public function max_loop_iterations(): void
    {
        $policy = new SandboxPolicy();
        $policy->maxLoopIterations(500);

        $this->assertSame(500, $policy->getMaxLoopIterations());
    }

    #[Test]
    public function fluent_chaining(): void
    {
        $policy = (new SandboxPolicy())
            ->allowFunction('date', 'count')
            ->denyFunction('file_put_contents')
            ->allowClass('stdClass')
            ->denyMethod('User', 'delete')
            ->maxIncludes(10)
            ->maxLoopIterations(1000);

        $this->assertTrue($policy->isFunctionAllowed('date'));
        $this->assertFalse($policy->isFunctionAllowed('file_put_contents'));
        $this->assertTrue($policy->isClassAllowed('stdClass'));
        $this->assertFalse($policy->isMethodAllowed('User', 'delete'));
        $this->assertSame(10, $policy->getMaxIncludes());
        $this->assertSame(1000, $policy->getMaxLoopIterations());
    }

    #[Test]
    public function getDeniedFunctions(): void
    {
        $policy = new SandboxPolicy();

        $denied = $policy->getDeniedFunctions();
        $this->assertContains('exec', $denied);
        $this->assertContains('eval', $denied);
    }

    #[Test]
    public function getAllowedFunctions(): void
    {
        $policy = new SandboxPolicy();
        $policy->allowFunction('date');

        $this->assertContains('date', $policy->getAllowedFunctions());
    }
}
