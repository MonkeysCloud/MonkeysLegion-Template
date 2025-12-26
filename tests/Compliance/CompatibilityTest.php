<?php

declare(strict_types=1);

namespace Tests\Compliance;

use Tests\TestCase;

class CompatibilityTest extends TestCase
{
    public function test_basic_echo()
    {
        $this->createView('echo', 'Hello {{ $name }}');
        $this->assertEquals('Hello World', $this->render('echo', ['name' => 'World']));
    }

    public function test_raw_echo()
    {
        $this->createView('raw', 'Hello {!! $html !!}');
        $html = '<strong>World</strong>';
        $this->assertEquals('Hello <strong>World</strong>', $this->render('raw', ['html' => $html]));
    }

    public function test_if_statement()
    {
        $this->createView('if', "
            @if(\$show) 
            Shown 
            @endif
        ");
        $this->assertStringContainsString('Shown', $this->render('if', ['show' => true]));
        $this->assertStringNotContainsString('Shown', $this->render('if', ['show' => false]));
    }

    public function test_foreach_loop()
    {
        $this->createView('loop', "
            @foreach(\$items as \$item)
            {{ \$item }}-
            @endforeach
        ");
        // Output will have tabs/spaces but we check content
        $output = $this->render('loop', ['items' => ['A', 'B', 'C']]);
        $this->assertStringContainsString('A-', $output);
        $this->assertStringContainsString('B-', $output);
        $this->assertStringContainsString('C-', $output);
    }

    public function test_comments()
    {
        $this->createView('comment', 'Hello {{-- comment --}}World');
        $this->assertEquals('Hello World', $this->render('comment'));
    }

    public function test_include()
    {
        $this->createView('child', 'Child content');
        $this->createView('parent', 'Parent @include("child")');
        
        $this->assertEquals('Parent Child content', $this->render('parent'));
    }


    protected function render(string $name, array $data = []): string
    {
        return $this->renderer->render($name, $data);
    }

    public function test_basic_component()
    {
        // Renderer looks for 'components.alert'
        $this->createView('components.alert', '<div class="alert">{{ $slot }}</div>');
        $this->createView('page', '<x-alert>Success</x-alert>');
        
        $this->assertEquals('<div class="alert">Success</div>', $this->render('page'));
    }
    
    public function test_component_attributes()
    {
        $this->createView('components.button', '<button type="{{ $type }}">{{ $slot }}</button>');
        $this->createView('form', '<x-button type="submit">Send</x-button>');
        
        $this->assertEquals('<button type="submit">Send</button>', $this->render('form'));
    }
}
