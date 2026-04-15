<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Events\ViewRendering;
use MonkeysLegion\Template\Events\ViewRendered;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for template render events (ViewRendering / ViewRendered).
 */
final class ViewEventTest extends TestCase
{
    #[Test]
    public function rendering_event_has_properties(): void
    {
        $event = new ViewRendering('home', ['title' => 'Home'], '/path/to/home.ml.php');

        $this->assertSame('home', $event->name);
        $this->assertSame(['title' => 'Home'], $event->data);
        $this->assertSame('/path/to/home.ml.php', $event->path);
    }

    #[Test]
    public function rendering_event_data_is_mutable(): void
    {
        $event = new ViewRendering('page', ['x' => 1]);

        // Listeners can modify data
        $event->data['y'] = 2;

        $this->assertSame(2, $event->data['y']);
        $this->assertCount(2, $event->data);
    }

    #[Test]
    public function rendered_event_has_properties(): void
    {
        $event = new ViewRendered('home', ['title' => 'Home'], '<h1>Home</h1>');

        $this->assertSame('home', $event->name);
        $this->assertSame(['title' => 'Home'], $event->data);
        $this->assertSame('<h1>Home</h1>', $event->output);
    }

    #[Test]
    public function rendered_event_output_is_mutable(): void
    {
        $event = new ViewRendered('page', [], '<p>Original</p>');

        // Listeners can post-process output
        $event->output = str_replace('Original', 'Modified', $event->output);

        $this->assertSame('<p>Modified</p>', $event->output);
    }

    #[Test]
    public function rendering_event_default_path(): void
    {
        $event = new ViewRendering('page', []);

        $this->assertSame('', $event->path);
    }

    #[Test]
    public function rendered_event_empty_output(): void
    {
        $event = new ViewRendered('empty', [], '');

        $this->assertSame('', $event->output);
    }
}
