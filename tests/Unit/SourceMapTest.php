<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\SourceMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceMap::class)]
final class SourceMapTest extends TestCase
{
    #[Test]
    public function add_and_resolve_exact_mapping(): void
    {
        $map = new SourceMap();
        $map->addMapping(10, '/views/home.ml.php', 5, 3);

        $result = $map->resolve(10);

        $this->assertNotNull($result);
        $this->assertSame('/views/home.ml.php', $result['sourcePath']);
        $this->assertSame(5, $result['sourceLine']);
        $this->assertSame(3, $result['sourceColumn']);
    }

    #[Test]
    public function resolve_nearest_preceding_mapping(): void
    {
        $map = new SourceMap();
        $map->addMapping(10, '/views/home.ml.php', 5);
        $map->addMapping(20, '/views/home.ml.php', 15);

        // Line 13 is between mappings — should use line 10 mapping + offset
        $result = $map->resolve(13);

        $this->assertNotNull($result);
        $this->assertSame('/views/home.ml.php', $result['sourcePath']);
        $this->assertSame(8, $result['sourceLine']); // 5 + (13 - 10) = 8
    }

    #[Test]
    public function resolve_returns_null_for_no_mappings(): void
    {
        $map = new SourceMap();

        $this->assertNull($map->resolve(10));
    }

    #[Test]
    public function resolve_returns_null_when_line_is_before_all_mappings(): void
    {
        $map = new SourceMap();
        $map->addMapping(10, '/views/home.ml.php', 5);

        $this->assertNull($map->resolve(3));
    }

    #[Test]
    public function serialize_and_deserialize_round_trip(): void
    {
        $map = new SourceMap();
        $map->addMapping(10, '/views/home.ml.php', 5, 3);
        $map->addMapping(20, '/views/layout.ml.php', 15, 1);

        $serialized   = $map->serialize();
        $deserialized = SourceMap::deserialize($serialized);

        $this->assertSame(
            $map->getMappings(),
            $deserialized->getMappings(),
        );
    }

    #[Test]
    public function from_compiled_source_parses_line_markers(): void
    {
        $compiled = <<<'PHP'
        <?php // compiled template
        // #line 1 "/views/home.ml.php"
        echo "Hello";
        // #line 5 "/views/home.ml.php"
        echo $name;
        echo " World";
        // #line 10 "/views/layout.ml.php"
        echo "Footer";
        PHP;

        $map = SourceMap::fromCompiledSource($compiled);

        $this->assertFalse($map->isEmpty());
        $this->assertSame(3, $map->count());

        // Line 2 maps to home.ml.php line 1
        $result = $map->resolve(2);
        $this->assertNotNull($result);
        $this->assertSame('/views/home.ml.php', $result['sourcePath']);
        $this->assertSame(1, $result['sourceLine']);
    }

    #[Test]
    public function is_empty_returns_true_for_new_map(): void
    {
        $map = new SourceMap();

        $this->assertTrue($map->isEmpty());
    }

    #[Test]
    public function count_method_works(): void
    {
        $map = new SourceMap();
        $this->assertSame(0, $map->count());

        $map->addMapping(1, '/test.php', 1);
        $this->assertSame(1, $map->count());

        $map->addMapping(5, '/test.php', 5);
        $this->assertSame(2, $map->count());
    }

    #[Test]
    public function multiple_files_in_source_map(): void
    {
        $map = new SourceMap();
        $map->addMapping(1, '/views/layout.ml.php', 1);
        $map->addMapping(10, '/views/home.ml.php', 1);
        $map->addMapping(20, '/views/layout.ml.php', 30);

        // Line 5 should resolve to layout
        $result = $map->resolve(5);
        $this->assertNotNull($result);
        $this->assertSame('/views/layout.ml.php', $result['sourcePath']);

        // Line 15 should resolve to home
        $result = $map->resolve(15);
        $this->assertNotNull($result);
        $this->assertSame('/views/home.ml.php', $result['sourcePath']);

        // Line 25 should resolve back to layout
        $result = $map->resolve(25);
        $this->assertNotNull($result);
        $this->assertSame('/views/layout.ml.php', $result['sourcePath']);
    }
}
