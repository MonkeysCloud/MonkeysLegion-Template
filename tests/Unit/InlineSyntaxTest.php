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

    public function testInlineEchoes(): void
    {
        $source = '<div>{{ $name }} - {!! $raw !!}</div>';
        $compiled = $this->compile($source);

        $this->assertStringContainsString('Escaper::html($name)', $compiled);
        $this->assertStringContainsString('<?= $raw ?? \'\' ?>', $compiled);
    }

    public function testConditionals(): void
    {
        $source = "@if(true)\nYES\n@elseif(false)\nNO\n@else\nMAYBE\n@endif";
        $compiled = $this->compile($source);

        $this->assertStringContainsString('if (true): ?>', $compiled);
        $this->assertStringContainsString('elseif (false): ?>', $compiled);
        $this->assertStringContainsString('else: ?>', $compiled);
        $this->assertStringContainsString('endif; ?>', $compiled);
    }

    public function testUnless(): void
    {
        $source = "@unless(false)\nUNLESS\n@endunless";
        $compiled = $this->compile($source);

        $this->assertStringContainsString('if (! (false)): ?>', $compiled);
        $this->assertStringContainsString('endif; ?>', $compiled);
    }

    public function testIssetEmpty(): void
    {
        $source = "@isset(\$v)\nISSET\n@endisset\n@empty(\$v)\nEMPTY\n@endempty";
        $compiled = $this->compile($source);

        $this->assertStringContainsString('if (isset($v)): ?>', $compiled);
        $this->assertStringContainsString('if (empty($v)): ?>', $compiled);
    }

    public function testLoops(): void
    {
        $source = "<ul>\n@foreach(\$items as \$i)\n<li>{{ \$i }}</li>\n@endforeach\n</ul>";
        $compiled = $this->compile($source);

        $this->assertStringContainsString('foreach($__currentLoopData as $i)', $compiled);
        $this->assertStringContainsString('Escaper::html($i)', $compiled);
        $this->assertStringContainsString('endforeach;', $compiled);

        $source2 = "@for(\$i=0;\$i<1;\$i++)\n{{\$i}}\n@endfor\n@while(false)\n@endwhile";
        $compiled2 = $this->compile($source2);

        $this->assertStringContainsString('for ($i=0;$i<1;$i++): ?>', $compiled2);
        $this->assertStringContainsString('while (false): ?>', $compiled2);
    }

    public function testSwitch(): void
    {
        $source = "@switch(\$v)\n@case(1)\nONE\n@break\n@default\nDEF\n@endswitch";
        $compiled = $this->compile($source);

        $this->assertStringContainsString('switch($v): ?>', $compiled);
        $this->assertStringContainsString('case 1: ?>', $compiled);
        $this->assertStringContainsString('break; ?>', $compiled);
        $this->assertStringContainsString('default: ?>', $compiled);
        $this->assertStringContainsString('endswitch; ?>', $compiled);
    }

    public function testInlineHelpers(): void
    {
        $source = '<div @class(["a"]) @style(["b"]) data-json="@json(["c"])">@js($d)</div>';
        $compiled = $this->compile($source);

        $this->assertStringContainsString('AttributeBag::conditional(["a"])', $compiled);
        $this->assertStringContainsString('json_encode(["c"]', $compiled);
        $this->assertStringContainsString('json_encode($d, JSON_UNESCAPED_UNICODE)', $compiled);
    }

    public function testLayouts(): void
    {
        $source = "@extends(\"lay\")\n@section(\"s\", \"v\")\n@yield(\"y\", \"d\")\n@include(\"inc\")";
        $compiled = $this->compile($source);

        $this->assertStringContainsString("\$__ml_extends = 'lay';", $compiled);
        $this->assertStringContainsString("\$__ml_sections['s']", $compiled);
        $this->assertStringContainsString("\$__ml_sections['y']", $compiled);
        $this->assertStringContainsString("render('inc'", $compiled);
    }

    public function testInlineComponents(): void
    {
        $source = '<div><x-ui.button :active="true">Click</x-ui.button> <x-ui.icon name="user" /></div>';
        $compiled = $this->compile($source);

        $this->assertStringContainsString('Component: ui.button', $compiled);
        $this->assertStringContainsString('Component: ui.icon', $compiled);
    }

    public function testMiscDirectives(): void
    {
        $source = "@auth\nA\n@endauth\n@guest\nG\n@endguest\n@env(\"p\")\nP\n@endenv\n@once\nO\n@endonce\n@stack(\"s\")";
        $compiled = $this->compile($source);

        $this->assertStringContainsString('auth()->check()', $compiled);
        $this->assertStringContainsString('!auth()->check()', $compiled);
        $this->assertStringContainsString('APP_ENV', $compiled);
        $this->assertStringContainsString('addOnceHash(', $compiled);
        $this->assertStringContainsString("yieldPush('s')", $compiled);
    }

    public function testSpecialDirectives(): void
    {
        $source = "@verbatim\n{{ \$a }}\n@endverbatim\n@php\n\$b=1;\n@endphp";
        $compiled = $this->compile($source);

        $this->assertStringContainsString('{{ $a }}', $compiled);
        $this->assertStringNotContainsString('Escaper::html($a)', $compiled);
        $this->assertStringContainsString('$b=1;', $compiled);
    }

    public function testInlinePhp(): void
    {
        $source = "@php(\$c=2)";
        $compiled = $this->compile($source);

        $this->assertStringContainsString('$c=2', $compiled);
    }
}
