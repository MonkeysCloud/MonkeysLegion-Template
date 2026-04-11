<?php

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\TestCase;

class InlineSyntaxTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler(new Parser());
    }

    private function compile(string $source): string
    {
        return $this->compiler->compile($source, 'test.ml.php');
    }

    /**
     * Test basic echoes and raw echoes inline
     */
    public function testInlineEchoes()
    {
        $source = '<div>{{ $name }} - {!! $raw !!}</div>';
        $compiled = $this->compile($source);
        
        $this->assertStringContainsString('Escaper::html($name)', $compiled);
        $this->assertStringContainsString('<?= $raw ?? \'\' ?>', $compiled);
    }

    /**
     * Test conditionals inline.
     * @NOTE: Many control structures in the current implementation use '^' and '$' with '/m' flag,
     * which might prevent them from being used inline if other text exists on the same line.
     */
    public function testInlineConditionals()
    {
        $source = '<span>@if(true) YES @elseif(false) NO @else MAYBE @endif</span>';
        $compiled = $this->compile($source);
        
        // Checking if they were compiled or left as raw strings
        $this->assertStringContainsString('<?php if (true): ?>', $compiled, '@if failed inline');
        $this->assertStringContainsString('<?php elseif (false): ?>', $compiled, '@elseif failed inline');
        $this->assertStringContainsString('<?php else: ?>', $compiled, '@else failed inline');
        $this->assertStringContainsString('<?php endif; ?>', $compiled, '@endif failed inline');
    }

    public function testInlineUnless()
    {
        $source = '<div>@unless(false) UNLESS @endunless</div>';
        $compiled = $this->compile($source);
        
        $this->assertStringContainsString('<?php if (! (false)): ?>', $compiled);
        $this->assertStringContainsString('<?php endif; ?>', $compiled);
    }

    public function testInlineIssetEmpty()
    {
        $source = '<div>@isset($v) ISSET @endisset @empty($v) EMPTY @endempty</div>';
        $compiled = $this->compile($source);
        
        $this->assertStringContainsString('<?php if (isset($v)): ?>', $compiled);
        $this->assertStringContainsString('<?php if (empty($v)): ?>', $compiled);
    }

    /**
     * Test loops inline
     */
    public function testInlineLoops()
    {
        $source = '<ul>@foreach($items as $i) <li>{{ $i }}</li> @endforeach</ul>';
        $compiled = $this->compile($source);
        
        $this->assertStringContainsString('foreach($__currentLoopData as $i)', $compiled);
        $this->assertStringContainsString('Escaper::html($i)', $compiled); // Actual output uses Escaper
        $this->assertStringContainsString('endforeach;', $compiled);

        $source2 = '<div>@for($i=0;$i<1;$i++) {{$i}} @endfor @while(false) @endwhile</div>';
        $compiled2 = $this->compile($source2);
        
        $this->assertStringContainsString('<?php for ($i=0;$i<1;$i++): ?>', $compiled2);
        $this->assertStringContainsString('<?php while (false): ?>', $compiled2);
    }

    /**
     * Test switch inline
     */
    public function testInlineSwitch()
    {
        // One-liner switch is complex but possible
        $source = '<div>@switch($v) @case(1) ONE @break @default DEF @endswitch</div>';
        $compiled = $this->compile($source);
        
        $this->assertStringContainsString('<?php switch($v): ?>', $compiled);
        $this->assertStringContainsString('<?php case 1: ?>', $compiled);
        $this->assertStringContainsString('<?php break; ?>', $compiled);
        $this->assertStringContainsString('<?php default: ?>', $compiled);
        $this->assertStringContainsString('<?php endswitch; ?>', $compiled);
    }

    /**
     * Test helpers like @json, @class, @style
     */
    public function testInlineHelpers()
    {
        $source = '<div @class(["a"]) @style(["b"]) data-json="@json(["c"])">@js($d)</div>';
        $compiled = $this->compile($source);
        
        $this->assertStringContainsString('AttributeBag::conditional(["a"])', $compiled);
        $this->assertStringContainsString('$__styles = [];', $compiled);
        $this->assertStringContainsString('json_encode(["c"]', $compiled);
        $this->assertStringContainsString('json_encode($d, JSON_UNESCAPED_UNICODE)', $compiled);
    }

    /**
     * Test layout and inclusion directives in one line
     */
    public function testInlineLayouts()
    {
        $source = '@extends("lay") @section("s", "v") @yield("y", "d") @include("inc")';
        $compiled = $this->compile($source);
        
        $this->assertStringContainsString('$__ml_extends = \'lay\';', $compiled);
        $this->assertStringContainsString('$__ml_sections[\'s\'] = \'v\';', $compiled);
        $this->assertStringContainsString('$__ml_sections[\'y\'] ?? \'d\'', $compiled);
        $this->assertStringContainsString('render(\'inc\'', $compiled);
    }

    /**
     * Test components inline
     */
    public function testInlineComponents()
    {
        $source = '<div><x-ui.button :active="true">Click</x-ui.button> <x-ui.icon name="user" /></div>';
        $compiled = $this->compile($source);
        
        $this->assertStringContainsString('Component: ui.button', $compiled);
        $this->assertStringContainsString('Component: ui.icon', $compiled);
    }

    /**
     * Test auth, guest, env, once, stack
     */
    public function testInlineMisc()
    {
        $source = '@auth A @endauth @guest G @endguest @env("p") P @endenv @once O @endonce @stack("s")';
        $compiled = $this->compile($source);
        
        $this->assertStringContainsString('if (function_exists(\'auth\') && auth()->check())', $compiled);
        $this->assertStringContainsString('if (!function_exists(\'auth\') || !auth()->check())', $compiled);
        $this->assertStringContainsString('APP_ENV', $compiled); // Check for part of the env logic
        $this->assertStringContainsString('if ($this->addOnceHash(', $compiled);
        $this->assertStringContainsString('yieldPush(\'s\')', $compiled);
    }

    /**
     * Test verbatim and php
     */
    public function testInlineSpecial()
    {
        $source = '@verbatim {{ $a }} @endverbatim @php $b=1; @endphp @php($c=2)';
        $compiled = $this->compile($source);
        
        $this->assertStringContainsString('{{ $a }}', $compiled);
        $this->assertStringNotContainsString('Escaper::html($a)', $compiled);
        $this->assertStringContainsString('<?php $b=1; ?>', $compiled);
        $this->assertStringContainsString('<?php $c=2; ?>', $compiled);
    }
}
