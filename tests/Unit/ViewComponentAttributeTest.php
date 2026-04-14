<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Attributes\ViewComponent;
use MonkeysLegion\Template\Attributes\FunctionComponent;
use MonkeysLegion\Template\Component;
use MonkeysLegion\Template\Support\AttributeBag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Stub with ViewComponent attribute
#[ViewComponent(name: 'test-badge')]
class StubAttributeBadgeComponent extends Component
{
    public function __construct(
        public string $text = '',
        public string $color = 'blue',
    ) {}

    public function render(): string
    {
        return 'components.badge';
    }
}

/**
 * Tests for component attribute system — #[ViewComponent], #[FunctionComponent], and AttributeBag.
 */
final class ViewComponentAttributeTest extends TestCase
{
    #[Test]
    public function viewComponent_attribute_stores_name(): void
    {
        $reflection = new \ReflectionClass(StubAttributeBadgeComponent::class);
        $attrs = $reflection->getAttributes(ViewComponent::class);

        $this->assertCount(1, $attrs);

        $viewComponent = $attrs[0]->newInstance();
        $this->assertSame('test-badge', $viewComponent->name);
    }

    #[Test]
    public function functionComponent_attribute_stores_name(): void
    {
        $attr = new FunctionComponent('icon');

        $this->assertSame('icon', $attr->name);
    }

    #[Test]
    public function attribute_bag_renders_html(): void
    {
        $bag = new AttributeBag(['class' => 'btn', 'id' => 'submit-btn']);

        $html = (string) $bag;

        $this->assertStringContainsString('class="btn"', $html);
        $this->assertStringContainsString('id="submit-btn"', $html);
    }

    #[Test]
    public function attribute_bag_merge(): void
    {
        $bag = new AttributeBag(['class' => 'btn', 'title' => 'Click']);
        $merged = $bag->merge(['class' => 'btn-primary', 'disabled' => true]);

        $html = (string) $merged;

        // merge should combine or override
        $this->assertStringContainsString('btn', $html);
    }

    #[Test]
    public function attribute_bag_except(): void
    {
        $bag = new AttributeBag(['class' => 'btn', 'id' => 'x', 'disabled' => '']);
        $filtered = $bag->except(['id']);

        $html = (string) $filtered;

        $this->assertStringNotContainsString('id=', $html);
        $this->assertStringContainsString('class="btn"', $html);
    }

    #[Test]
    public function attribute_bag_only(): void
    {
        $bag = new AttributeBag(['class' => 'btn', 'id' => 'x', 'title' => 'T']);
        $filtered = $bag->only(['class', 'title']);

        $html = (string) $filtered;

        $this->assertStringContainsString('class="btn"', $html);
        $this->assertStringContainsString('title="T"', $html);
        $this->assertStringNotContainsString('id=', $html);
    }

    #[Test]
    public function attribute_bag_has(): void
    {
        $bag = new AttributeBag(['class' => 'btn']);

        $this->assertTrue($bag->has('class'));
        $this->assertFalse($bag->has('nonexistent'));
    }

    #[Test]
    public function attribute_bag_get(): void
    {
        $bag = new AttributeBag(['class' => 'btn']);

        $this->assertSame('btn', $bag->get('class'));
        $this->assertSame('fallback', $bag->get('missing', 'fallback'));
    }

    #[Test]
    public function component_withAttributes_populates_bag(): void
    {
        $component = new StubAttributeBadgeComponent(text: 'New');
        $component->withAttributes(['class' => 'custom', 'data-tooltip' => 'Help']);

        $bag = $component->getAttributes();

        $this->assertTrue($bag->has('class'));
        $this->assertTrue($bag->has('data-tooltip'));
    }

    #[Test]
    public function attribute_conditional_class(): void
    {
        $result = AttributeBag::conditional([
            'btn',
            'active' => true,
            'disabled' => false,
        ]);

        $this->assertStringContainsString('btn', $result);
        $this->assertStringContainsString('active', $result);
        $this->assertStringNotContainsString('disabled', $result);
    }
}
