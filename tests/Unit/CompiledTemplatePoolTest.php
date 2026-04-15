<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Cache\CompiledTemplatePool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompiledTemplatePoolTest extends TestCase
{
    #[Test]
    public function has_returns_false_initially(): void
    {
        $pool = new CompiledTemplatePool();
        $this->assertFalse($pool->has('page'));
    }

    #[Test]
    public function put_then_has_returns_true(): void
    {
        $pool = new CompiledTemplatePool();
        $pool->put('page', '/cache/page.php', 1000.0);

        $this->assertTrue($pool->has('page'));
    }

    #[Test]
    public function getPath_returns_stored_path(): void
    {
        $pool = new CompiledTemplatePool();
        $pool->put('page', '/cache/page_abc.php', 1000.0);

        $this->assertSame('/cache/page_abc.php', $pool->getPath('page'));
    }

    #[Test]
    public function getMtime_returns_stored_mtime(): void
    {
        $pool = new CompiledTemplatePool();
        $pool->put('page', '/cache/page.php', 1234567890.0);

        $this->assertSame(1234567890.0, $pool->getMtime('page'));
    }

    #[Test]
    public function forget_removes_entry(): void
    {
        $pool = new CompiledTemplatePool();
        $pool->put('page', '/cache/page.php', 1000.0);

        $pool->forget('page');

        $this->assertFalse($pool->has('page'));
        $this->assertNull($pool->getMtime('page'));
    }

    #[Test]
    public function clear_removes_all(): void
    {
        $pool = new CompiledTemplatePool();
        $pool->put('page1', '/c/p1.php', 100.0);
        $pool->put('page2', '/c/p2.php', 200.0);

        $pool->clear();

        $this->assertFalse($pool->has('page1'));
        $this->assertFalse($pool->has('page2'));
    }

    #[Test]
    public function stats_tracks_hits_and_misses(): void
    {
        $pool = new CompiledTemplatePool();
        $pool->put('a', '/c/a.php', 1.0);  // miss
        $pool->put('b', '/c/b.php', 2.0);  // miss
        $pool->getPath('a');                 // hit
        $pool->getPath('b');                 // hit
        $pool->getPath('a');                 // hit

        $stats = $pool->getStats();
        $this->assertSame(3, $stats['hits']);
        $this->assertSame(2, $stats['misses']);
        $this->assertSame(2, $stats['size']);
    }
}
