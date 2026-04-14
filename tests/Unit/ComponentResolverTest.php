<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\ComponentResolver;
use MonkeysLegion\Template\Component;
use MonkeysLegion\Template\Contracts\ComponentInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Stub component for testing
class StubAlertComponent extends Component
{
    public function __construct(
        public string $type = 'info',
        public string $message = '',
    ) {}

    public function render(): string
    {
        return 'components.alert';
    }

    public function getIcon(): string
    {
        return match ($this->type) {
            'error' => '❌',
            'warning' => '⚠️',
            'success' => '✅',
            default => 'ℹ️',
        };
    }
}

/**
 * Tests for ComponentResolver — Phase 3 component resolution.
 */
final class ComponentResolverTest extends TestCase
{
    private ComponentResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ComponentResolver();
    }

    #[Test]
    public function registers_class_component(): void
    {
        $this->resolver->registerClass('alert', StubAlertComponent::class);

        $this->assertTrue($this->resolver->has('alert'));
        $this->assertContains('alert', $this->resolver->getRegisteredClassNames());
    }

    #[Test]
    public function registers_function_component(): void
    {
        $this->resolver->registerFunction('badge', fn(string $text) => "<span>{$text}</span>");

        $this->assertTrue($this->resolver->has('badge'));
        $this->assertContains('badge', $this->resolver->getRegisteredFunctionNames());
    }

    #[Test]
    public function resolves_class_component(): void
    {
        $this->resolver->registerClass('alert', StubAlertComponent::class);

        $result = $this->resolver->resolve('alert', ['type' => 'error', 'message' => 'Fail']);

        $this->assertSame('class', $result['type']);
        $this->assertInstanceOf(ComponentInterface::class, $result['value']);
    }

    #[Test]
    public function resolves_class_with_constructor_args(): void
    {
        $this->resolver->registerClass('alert', StubAlertComponent::class);

        $result = $this->resolver->resolve('alert', ['type' => 'success', 'message' => 'Done']);

        /** @var StubAlertComponent $component */
        $component = $result['value'];
        $this->assertSame('success', $component->type);
        $this->assertSame('Done', $component->message);
    }

    #[Test]
    public function resolves_function_component(): void
    {
        $this->resolver->registerFunction('badge', fn(string $text) => "<span>{$text}</span>");

        $result = $this->resolver->resolve('badge');

        $this->assertSame('function', $result['type']);
        $this->assertIsCallable($result['value']);
    }

    #[Test]
    public function resolves_anonymous_template(): void
    {
        $tempDir = sys_get_temp_dir() . '/ml_components_' . uniqid();
        mkdir($tempDir, 0755, true);
        file_put_contents("{$tempDir}/card.ml.php", '<div class="card">{{ $slot }}</div>');

        $this->resolver->addComponentPath($tempDir);
        $result = $this->resolver->resolve('card');

        $this->assertSame('anonymous', $result['type']);
        $this->assertIsString($result['value']);
        $this->assertStringContainsString('card.ml.php', $result['value']);

        // Cleanup
        unlink("{$tempDir}/card.ml.php");
        rmdir($tempDir);
    }

    #[Test]
    public function resolves_anonymous_template_with_index(): void
    {
        $tempDir = sys_get_temp_dir() . '/ml_components_' . uniqid();
        mkdir("{$tempDir}/modal", 0755, true);
        file_put_contents("{$tempDir}/modal/index.ml.php", '<div class="modal">{{ $slot }}</div>');

        $this->resolver->addComponentPath($tempDir);
        $result = $this->resolver->resolve('modal');

        $this->assertSame('anonymous', $result['type']);
        $this->assertIsString($result['value']);
        $this->assertStringContainsString('index.ml.php', $result['value']);

        // Cleanup
        unlink("{$tempDir}/modal/index.ml.php");
        rmdir("{$tempDir}/modal");
        rmdir($tempDir);
    }

    #[Test]
    public function resolves_none_for_unknown(): void
    {
        $result = $this->resolver->resolve('nonexistent');

        $this->assertSame('none', $result['type']);
        $this->assertNull($result['value']);
    }

    #[Test]
    public function class_priority_over_function(): void
    {
        $this->resolver->registerClass('alert', StubAlertComponent::class);
        $this->resolver->registerFunction('alert', fn() => 'function');

        $result = $this->resolver->resolve('alert');

        // Class components take priority
        $this->assertSame('class', $result['type']);
    }

    #[Test]
    public function discovers_component_from_attribute(): void
    {
        // Note: StubAlertComponent doesn't have #[ViewComponent], so this won't register
        $this->resolver->discoverFromClass(StubAlertComponent::class);

        // Should not be registered since there's no attribute
        $this->assertNotContains('alert', $this->resolver->getRegisteredClassNames());
    }
}
