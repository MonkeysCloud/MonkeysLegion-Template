<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Testing\TestComponent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TestComponent — component isolation testing helper.
 */
final class TestComponentTest extends TestCase
{
    #[Test]
    public function make_creates_instance(): void
    {
        $component = TestComponent::make('alert', ['type' => 'error']);

        $this->assertSame('alert', $component->getName());
        $this->assertSame(['type' => 'error'], $component->getProps());
    }

    #[Test]
    public function withSlot_adds_slot(): void
    {
        $component = TestComponent::make('card')
            ->withSlot('default', '<p>Content</p>')
            ->withSlot('footer', '<footer>End</footer>');

        $this->assertCount(2, $component->getSlots());
        $this->assertSame('<p>Content</p>', $component->getSlots()['default']);
    }

    #[Test]
    public function withProp_adds_prop(): void
    {
        $component = TestComponent::make('button')
            ->withProp('variant', 'primary')
            ->withProp('disabled', true);

        $this->assertSame('primary', $component->getProp('variant'));
        $this->assertTrue($component->getProp('disabled'));
    }

    #[Test]
    public function getProp_returns_default(): void
    {
        $component = TestComponent::make('button');

        $this->assertNull($component->getProp('nonexistent'));
        $this->assertSame('fallback', $component->getProp('missing', 'fallback'));
    }

    #[Test]
    public function setRenderedOutput_and_assertions(): void
    {
        $component = TestComponent::make('alert', ['type' => 'success']);
        $component->setRenderedOutput('<div class="alert alert-success">✓ Success</div>');

        $component->assertSee('Success')
            ->assertDontSee('Error');
    }

    #[Test]
    public function toTestView_creates_view(): void
    {
        $component = TestComponent::make('badge', ['text' => 'New']);
        $component->setRenderedOutput('<span class="badge">New</span>');

        $testView = $component->toTestView();

        $this->assertSame('component:badge', $testView->getName());
        $testView->assertSee('badge');
    }

    #[Test]
    public function fluent_builder_pattern(): void
    {
        $component = TestComponent::make('card')
            ->withProp('title', 'Card Title')
            ->withProp('elevated', true)
            ->withSlot('default', '<p>Body</p>')
            ->withSlot('actions', '<button>Save</button>');

        $this->assertSame('Card Title', $component->getProp('title'));
        $this->assertTrue($component->getProp('elevated'));
        $this->assertCount(2, $component->getSlots());
    }

    #[Test]
    public function empty_rendered_output(): void
    {
        $component = TestComponent::make('empty');

        $this->assertNull($component->getRenderedOutput());
    }
}
