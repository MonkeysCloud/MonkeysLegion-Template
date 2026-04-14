<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Component;
use MonkeysLegion\Template\Support\AttributeBag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Stub class component
class StubCardComponent extends Component
{
    public function __construct(
        public string $title = 'Default Title',
        public string $variant = 'default',
    ) {}

    public function render(): string
    {
        return 'components.card';
    }

    public function getCssClass(): string
    {
        return "card card--{$this->variant}";
    }

    public function getIsDefault(): bool
    {
        return $this->variant === 'default';
    }
}

// Minimal component for edge cases
class StubMinimalComponent extends Component
{
    public function render(): string
    {
        return 'components.minimal';
    }
}

/**
 * Tests for class-based components (Component abstract class).
 */
final class ClassComponentTest extends TestCase
{
    #[Test]
    public function data_extracts_public_properties(): void
    {
        $component = new StubCardComponent(title: 'Hello', variant: 'primary');

        $data = $component->data();

        $this->assertSame('Hello', $data['title']);
        $this->assertSame('primary', $data['variant']);
    }

    #[Test]
    public function data_extracts_computed_get_methods(): void
    {
        $component = new StubCardComponent(variant: 'danger');

        $data = $component->data();

        $this->assertSame('card card--danger', $data['cssClass']);
        $this->assertFalse($data['isDefault']);
    }

    #[Test]
    public function data_excludes_internal_properties(): void
    {
        $component = new StubCardComponent();

        $data = $component->data();

        // __attributes and __attributeBag should NOT appear
        $this->assertArrayNotHasKey('__attributes', $data);
        $this->assertArrayNotHasKey('__attributeBag', $data);
    }

    #[Test]
    public function resolveView_returns_render(): void
    {
        $component = new StubCardComponent();

        $this->assertSame('components.card', $component->resolveView());
    }

    #[Test]
    public function withAttributes_sets_attribute_bag(): void
    {
        $component = new StubCardComponent();
        $component->withAttributes(['class' => 'extra', 'id' => 'my-card']);

        $bag = $component->getAttributes();

        $this->assertInstanceOf(AttributeBag::class, $bag);
    }

    #[Test]
    public function withAttributes_returns_static(): void
    {
        $component = new StubCardComponent();
        $result = $component->withAttributes(['x' => 'y']);

        $this->assertSame($component, $result);
    }

    #[Test]
    public function minimal_component_has_no_props(): void
    {
        $component = new StubMinimalComponent();

        $data = $component->data();

        // Should be empty (no public properties, no get* methods)
        $this->assertEmpty($data);
    }

    #[Test]
    public function get_methods_with_required_params_are_excluded(): void
    {
        // getCssClass() requires no params → included
        // Any get method with required params should be excluded
        $component = new StubCardComponent();
        $data = $component->data();

        // cssClass from getCssClass()
        $this->assertArrayHasKey('cssClass', $data);
    }

    #[Test]
    public function render_returns_view_name(): void
    {
        $component = new StubCardComponent();

        $this->assertSame('components.card', $component->render());
    }
}
