<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\VariableScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for VariableScope macro and function component scope methods.
 */
final class VariableScopeMacroTest extends TestCase
{
    #[Test]
    public function enterMacroScope_isolates_variables(): void
    {
        $scope = new VariableScope(['name' => 'outer']);

        $scope->enterMacroScope(['arg1' => 'hello']);

        // Current scope should only have macro args
        $current = $scope->getCurrentScope();
        $this->assertSame('hello', $current['arg1']);
        $this->assertArrayNotHasKey('name', $current);

        $scope->exitMacroScope();

        // Back to parent scope
        $current = $scope->getCurrentScope();
        $this->assertSame('outer', $current['name']);
    }

    #[Test]
    public function exitMacroScope_does_not_underflow(): void
    {
        $scope = new VariableScope(['root' => true]);

        // Try to exit more than we entered
        $scope->exitMacroScope();

        $current = $scope->getCurrentScope();
        $this->assertTrue($current['root']);
    }

    #[Test]
    public function nested_macro_scopes(): void
    {
        $scope = new VariableScope(['base' => 1]);

        $scope->enterMacroScope(['level' => 'one']);
        $this->assertSame('one', $scope->getCurrentScope()['level']);

        $scope->enterMacroScope(['level' => 'two']);
        $this->assertSame('two', $scope->getCurrentScope()['level']);

        $scope->exitMacroScope();
        $this->assertSame('one', $scope->getCurrentScope()['level']);

        $scope->exitMacroScope();
        $this->assertSame(1, $scope->getCurrentScope()['base']);
    }

    #[Test]
    public function enterFunctionComponentScope_isolates(): void
    {
        $scope = new VariableScope(['page' => 'home']);

        $scope->enterFunctionComponentScope(['text' => 'Badge', 'color' => 'blue']);

        $current = $scope->getCurrentScope();
        $this->assertSame('Badge', $current['text']);
        $this->assertSame('blue', $current['color']);
        $this->assertArrayNotHasKey('page', $current);

        $scope->exitFunctionComponentScope();
        $this->assertSame('home', $scope->getCurrentScope()['page']);
    }

    #[Test]
    public function exitFunctionComponentScope_does_not_underflow(): void
    {
        $scope = new VariableScope(['root' => 'yes']);

        $scope->exitFunctionComponentScope();

        $this->assertSame('yes', $scope->getCurrentScope()['root']);
    }

    #[Test]
    public function macro_aware_still_works(): void
    {
        $scope = new VariableScope(['global' => 'shared']);

        $scope->enterMacroScope(['local' => 'macro']);

        // getAware can see parent scope
        $this->assertSame('shared', $scope->getAware('global'));
        $this->assertSame('macro', $scope->getAware('local'));

        $scope->exitMacroScope();
    }
}
