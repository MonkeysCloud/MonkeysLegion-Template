<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

/**
 * Dedicated integration tests for @forelse / @empty / @endforelse.
 */
class ForelseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer->clearCache();
    }

    public function testForelseRendersItems(): void
    {
        $this->createView('forelse1', '
            @forelse($users as $user)
                Name: {{ $user }}
            @empty
                No users
            @endforelse
        ');

        $output = $this->renderer->render('forelse1', ['users' => ['Alice', 'Bob']]);

        $this->assertStringContainsString('Name: Alice', $output);
        $this->assertStringContainsString('Name: Bob', $output);
        $this->assertStringNotContainsString('No users', $output);
    }

    public function testForelseRendersEmpty(): void
    {
        $this->createView('forelse2', '
            @forelse($users as $user)
                Name: {{ $user }}
            @empty
                No users found
            @endforelse
        ');

        $output = $this->renderer->render('forelse2', ['users' => []]);

        $this->assertStringContainsString('No users found', $output);
        $this->assertStringNotContainsString('Name:', $output);
    }

    public function testForelseWithLoopVariable(): void
    {
        $this->createView('forelse3', '
            @forelse($items as $item)
                {{ $loop->iteration }}: {{ $item }}
            @empty
                Empty
            @endforelse
        ');

        $output = $this->renderer->render('forelse3', ['items' => ['A', 'B', 'C']]);

        $this->assertStringContainsString('1: A', $output);
        $this->assertStringContainsString('2: B', $output);
        $this->assertStringContainsString('3: C', $output);
    }

    public function testForelseCoexistsWithStandaloneEmpty(): void
    {
        $this->createView('forelse4', '
            @forelse($items as $item)
                {{ $item }}
            @empty
                No items
            @endforelse
            @empty($list)
                List is empty
            @endempty
        ');

        $output = $this->renderer->render('forelse4', [
            'items' => [],
            'list' => [],
        ]);

        $this->assertStringContainsString('No items', $output);
        $this->assertStringContainsString('List is empty', $output);
    }

    public function testForelseWithKeyValue(): void
    {
        $this->createView('forelse5', '
            @forelse($data as $key => $val)
                {{ $key }}={{ $val }}
            @empty
                Empty
            @endforelse
        ');

        $output = $this->renderer->render('forelse5', ['data' => ['a' => '1', 'b' => '2']]);

        $this->assertStringContainsString('a=1', $output);
        $this->assertStringContainsString('b=2', $output);
    }
}
