<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\ComponentResolver;
use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for <x-dynamic-component> — runtime component resolution.
 */
final class DynamicComponentTest extends TestCase
{
    private ComponentResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ComponentResolver();
    }

    #[Test]
    public function dynamic_resolve_changes_by_name(): void
    {
        $this->resolver->registerFunction('alert', fn() => '<div class="alert">Alert</div>');
        $this->resolver->registerFunction('badge', fn() => '<span class="badge">Badge</span>');

        // Resolve dynamically based on a variable
        $componentName = 'alert';
        $result = $this->resolver->resolve($componentName);

        $this->assertSame('function', $result['type']);

        $componentName = 'badge';
        $result = $this->resolver->resolve($componentName);

        $this->assertSame('function', $result['type']);
    }

    #[Test]
    public function dynamic_resolve_with_class_component(): void
    {
        $this->resolver->registerClass('alert', StubAlertComponent::class);

        $types = ['alert'];

        foreach ($types as $type) {
            $result = $this->resolver->resolve($type);
            $this->assertSame('class', $result['type']);
        }
    }

    #[Test]
    public function dynamic_resolve_returns_none_for_unknown(): void
    {
        $result = $this->resolver->resolve('nonexistent-component');

        $this->assertSame('none', $result['type']);
        $this->assertNull($result['value']);
    }

    #[Test]
    public function dynamic_resolve_passes_attributes(): void
    {
        $this->resolver->registerClass('alert', StubAlertComponent::class);

        $result = $this->resolver->resolve('alert', ['type' => 'warning', 'message' => 'Watch out']);

        /** @var StubAlertComponent $component */
        $component = $result['value'];
        $this->assertSame('warning', $component->type);
        $this->assertSame('Watch out', $component->message);
    }

    #[Test]
    public function dynamic_resolve_with_dot_notation(): void
    {
        $tempDir = sys_get_temp_dir() . '/ml_dynamic_' . uniqid();
        mkdir("{$tempDir}/ui", 0755, true);
        file_put_contents("{$tempDir}/ui/button.ml.php", '<button>{{ $slot }}</button>');

        $this->resolver->addComponentPath($tempDir);

        $result = $this->resolver->resolve('ui.button');

        $this->assertSame('anonymous', $result['type']);

        // Cleanup
        unlink("{$tempDir}/ui/button.ml.php");
        rmdir("{$tempDir}/ui");
        rmdir($tempDir);
    }
}
