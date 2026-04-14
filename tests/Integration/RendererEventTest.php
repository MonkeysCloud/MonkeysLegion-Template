<?php

declare(strict_types=1);

namespace Tests\Integration;

use MonkeysLegion\Template\Events\ViewRendered;
use MonkeysLegion\Template\Events\ViewRendering;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for Renderer-level event dispatch (Gap #5).
 */
final class RendererEventTest extends TestCase
{
    #[Test]
    public function onRendering_modifies_data(): void
    {
        $this->renderer->onRendering(function (ViewRendering $event): void {
            $event->data['injected'] = 'from_event';
        });

        $this->createView('event_test', '<p>{{ $injected }}</p>');

        $output = $this->renderer->render('event_test');

        $this->assertStringContainsString('from_event', $output);
    }

    #[Test]
    public function onRendered_modifies_output(): void
    {
        $this->renderer->onRendered(function (ViewRendered $event): void {
            $event->output = str_replace('Hello', 'Goodbye', $event->output);
        });

        $this->createView('rendered_test', '<p>Hello</p>');

        $output = $this->renderer->render('rendered_test');

        $this->assertStringContainsString('Goodbye', $output);
        $this->assertStringNotContainsString('Hello', $output);
    }

    #[Test]
    public function multiple_rendering_listeners(): void
    {
        $this->renderer->onRendering(function (ViewRendering $event): void {
            $event->data['a'] = 1;
        });
        $this->renderer->onRendering(function (ViewRendering $event): void {
            $event->data['b'] = 2;
        });

        $this->createView('multi_event', '{{ $a }}-{{ $b }}');

        $output = $this->renderer->render('multi_event');

        $this->assertStringContainsString('1', $output);
        $this->assertStringContainsString('2', $output);
    }

    #[Test]
    public function rendered_event_receives_final_output(): void
    {
        $captured = '';
        $this->renderer->onRendered(function (ViewRendered $event) use (&$captured): void {
            $captured = $event->output;
        });

        $this->createView('capture_test', '<h1>Captured</h1>');

        $this->renderer->render('capture_test');

        $this->assertStringContainsString('Captured', $captured);
    }

    #[Test]
    public function rendering_event_has_view_name(): void
    {
        $capturedName = '';
        $this->renderer->onRendering(function (ViewRendering $event) use (&$capturedName): void {
            $capturedName = $event->name;
        });

        $this->createView('name_check', 'content');

        $this->renderer->render('name_check');

        $this->assertSame('name_check', $capturedName);
    }
}
