<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

class FeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear cache before each test (optional, as fresh dir is used per test in base)
        $this->renderer->clearCache();
    }

    public function testStackPushPrepend(): void
    {
        $this->createView('layouts.app', '
            <html>
                <head>
                    @stack("styles")
                </head>
                <body>
                    @yield("content")
                    @stack("scripts")
                </body>
            </html>
        ');

        $this->createView('page', '
            @extends("layouts.app")
            @section("content")
                <h1>Hello</h1>
                @push("scripts")
                    <script>console.log("pushed");</script>
                @endpush
                @prepend("styles")
                    <style>body { color: red; }</style>
                @endprepend
            @endsection
        ');

        $output = $this->renderer->render('page');

        $this->assertStringContainsString('<style>body { color: red; }</style>', $output);
        $this->assertStringContainsString('<script>console.log("pushed");</script>', $output);
    }

    public function testLoopVariable(): void
    {
        $this->createView('loops', '
            <ul>
            @foreach($items as $item)
                <li class="{{ $loop->first ? "first" : "" }} {{ $loop->last ? "last" : "" }}">
                    {{ $loop->iteration }}: {{ $item }} (Remaining: {{ $loop->remaining }})
                </li>
            @endforeach
            </ul>
        ');

        $output = $this->renderer->render('loops', ['items' => ['A', 'B', 'C']]);

        $this->assertStringContainsString('1: A (Remaining: 2)', $output);
        $this->assertStringContainsString('2: B (Remaining: 1)', $output);
        $this->assertStringContainsString('3: C (Remaining: 0)', $output);
        $this->assertStringContainsString('class="first "', $output);
        $this->assertStringContainsString('class=" last"', $output);
    }

    public function testConditionalsSugar(): void
    {
        $this->createView('sugar', '
            @unless($isAdmin)
                Not Admin
            @endunless
            
            @isset($name)
                Has Name: {{ $name }}
            @endisset
            
            @empty($list)
                List is empty
            @endempty

            @switch($i)
                @case(1)
                    One
                    @break
                @case(2)
                    Two
                    @break
                @default
                    Other
            @endswitch
        ');

        $output = $this->renderer->render('sugar', [
            'isAdmin' => false,
            'name' => 'John',
            'list' => [],
            'i' => 2
        ]);

        $this->assertStringContainsString('Not Admin', $output);
        $this->assertStringContainsString('Has Name: John', $output);
        $this->assertStringContainsString('List is empty', $output);
        $this->assertStringContainsString('Two', $output);
    }

    public function testVerbatim(): void
    {
        $this->createView('raw', '
            @verbatim
                Hello {{ name }}
            @endverbatim
        ');

        $output = $this->renderer->render('raw', ['name' => 'World']);
        $this->assertStringContainsString('Hello {{ name }}', $output);
    }

    public function testOnce(): void
    {
        $this->createComponent('alert', '
            @once
                <script>console.log("alert init");</script>
            @endonce
            <div class="alert">{{ $slot }}</div>
        ');

        $this->createView('page_once', '
            <x-alert>Message 1</x-alert>
            <x-alert>Message 2</x-alert>
        ');

        $output = $this->renderer->render('page_once');

        // Should contain the script ONLY ONCE
        $this->assertEquals(1, substr_count($output, 'console.log("alert init")'));
        $this->assertStringContainsString('Message 1', $output);
        $this->assertStringContainsString('Message 2', $output);
    }

    public function testAware(): void
    {
        $this->createComponent('child', '
            @aware(["color" => "gray"])
            <span style="color: {{ $color }}">Child</span>
        ');

        $this->createComponent('wrapper', '
            <div class="wrapper">
                <x-child />
            </div>
        ');

        $this->createView('aware_test', '<x-wrapper color="blue" />');

        $output = $this->renderer->render('aware_test');
        $this->assertStringContainsString('color: blue', $output);
    }

    public function testIncludeWhen(): void
    {
        $this->createView('inc_when', '
            @includeWhen(true, "included")
            @includeWhen(false, "included")
        ');

        $this->createView('included', 'INCLUDED');

        $output = $this->renderer->render('inc_when');

        $this->assertEquals(1, substr_count($output, 'INCLUDED'));
    }
}
