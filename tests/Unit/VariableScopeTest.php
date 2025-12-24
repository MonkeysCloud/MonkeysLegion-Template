<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\VariableScope;
use PHPUnit\Framework\TestCase;

class VariableScopeTest extends TestCase
{
    private VariableScope $scope;

    protected function setUp(): void
    {
        VariableScope::setCurrent(new VariableScope([]));
        $this->scope = VariableScope::getCurrent();
    }

    public function testItStartsEmpty(): void
    {
        $this->assertEmpty($this->scope->getCurrentScope());
        $this->assertEquals(1, $this->scope->getDepth());
    }

    public function testItManagesScopeStack(): void
    {
        // Add var to root scope
        $this->scope->set('foo', 'bar');
        $this->assertEquals('bar', $this->scope->get('foo'));

        // Push new scope (inheritance)
        $this->scope->pushScope(['baz' => 'qux']);
        $this->assertEquals(2, $this->scope->getDepth());

        // Should inherit 'foo'
        $this->assertEquals('bar', $this->scope->get('foo'));
        $this->assertEquals('qux', $this->scope->get('baz'));

        // Pop scope
        $this->scope->popScope();
        $this->assertEquals(1, $this->scope->getDepth());
        $this->assertFalse($this->scope->has('baz'));
    }

    public function testIsolatedScopeDoesNotInherit(): void
    {
        $this->scope->set('global', 'value');

        $this->scope->createIsolatedScope(['local' => 'data']);

        $this->assertEquals(2, $this->scope->getDepth());
        $this->assertTrue($this->scope->has('local'));
        $this->assertFalse($this->scope->has('global')); // Should NOT inherit
    }

    public function testGlobalDataInjection(): void
    {
        $this->scope->setGlobalData(['app_name' => 'MonkeysLegion']);

        // Should be available in root
        $this->assertEquals('MonkeysLegion', $this->scope->get('app_name'));

        // Push scope
        $this->scope->pushScope();
        $this->assertEquals('MonkeysLegion', $this->scope->get('app_name'));

        // Reset
        $this->scope->reset();
        $this->assertEquals('MonkeysLegion', $this->scope->get('app_name'));
    }
}
