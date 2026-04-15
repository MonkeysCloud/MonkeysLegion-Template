<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Phase 2 directives added to the Compiler.
 */
#[CoversClass(Compiler::class)]
final class Phase2DirectivesTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler(new Parser());
    }

    private function compile(string $source): string
    {
        return $this->compiler->compile($source, '/tmp/test.ml.php');
    }

    // =========================================================================
    // @forelse / @empty / @endforelse
    // =========================================================================

    #[Test]
    public function forelse_compiles_with_empty_clause(): void
    {
        $compiled = $this->compile(
            "@forelse(\$items as \$item)\n{{ \$item }}\n@empty\nNo items\n@endforelse"
        );

        $this->assertStringContainsString('$__forelseData', $compiled);
        $this->assertStringContainsString('$__forelseEmpty = true', $compiled);
        $this->assertStringContainsString('$__forelseEmpty = false', $compiled);
        $this->assertStringContainsString('if ($__forelseEmpty)', $compiled);
        $this->assertStringContainsString('endif;', $compiled);
    }

    #[Test]
    public function forelse_does_not_conflict_with_standalone_empty(): void
    {
        $compiled = $this->compile("@empty(\$list)\nEmpty\n@endempty");

        $this->assertStringContainsString('if (empty($list))', $compiled);
        $this->assertStringNotContainsString('forelseEmpty', $compiled);
    }

    // =========================================================================
    // @fragment / @endfragment
    // =========================================================================

    #[Test]
    public function fragment_compiles_to_ob_start(): void
    {
        $compiled = $this->compile("@fragment('user-list')\n<ul></ul>\n@endfragment");

        $this->assertStringContainsString("__fragmentName = 'user-list'", $compiled);
        $this->assertStringContainsString('ob_start()', $compiled);
        $this->assertStringContainsString('ob_get_clean()', $compiled);
        $this->assertStringContainsString('isHtmxRequest()', $compiled);
    }

    // =========================================================================
    // @teleport / @endteleport
    // =========================================================================

    #[Test]
    public function teleport_compiles(): void
    {
        $compiled = $this->compile("@teleport('#modals')\n<div>Modal</div>\n@endteleport");

        $this->assertStringContainsString('ob_start()', $compiled);
        $this->assertStringContainsString('teleport:#modals', $compiled);
        $this->assertStringContainsString('ob_get_clean()', $compiled);
    }

    // =========================================================================
    // @can / @endcan
    // =========================================================================

    #[Test]
    public function can_compiles_with_ability(): void
    {
        $compiled = $this->compile("@can('edit')\nEdit\n@endcan");

        $this->assertStringContainsString("auth()->can('edit')", $compiled);
        $this->assertStringContainsString('endif;', $compiled);
    }

    #[Test]
    public function can_compiles_with_model(): void
    {
        $compiled = $this->compile("@can('edit', \$post)\nEdit\n@endcan");

        $this->assertStringContainsString("auth()->can('edit', \$post)", $compiled);
    }

    // =========================================================================
    // @cannot / @endcannot
    // =========================================================================

    #[Test]
    public function cannot_compiles(): void
    {
        $compiled = $this->compile("@cannot('delete')\nNo Delete\n@endcannot");

        $this->assertStringContainsString("auth()->cannot('delete')", $compiled);
    }

    // =========================================================================
    // @hasSection / @endhasSection
    // =========================================================================

    #[Test]
    public function hasSection_compiles(): void
    {
        $compiled = $this->compile("@hasSection('sidebar')\nSidebar exists\n@endhasSection");

        $this->assertStringContainsString("isset(\$__sections['sidebar'])", $compiled);
    }

    // =========================================================================
    // @sectionMissing / @endsectionMissing
    // =========================================================================

    #[Test]
    public function sectionMissing_compiles(): void
    {
        $compiled = $this->compile("@sectionMissing('sidebar')\nNo sidebar\n@endsectionMissing");

        $this->assertStringContainsString("!isset(\$__sections['sidebar'])", $compiled);
    }

    // =========================================================================
    // @production / @endproduction
    // =========================================================================

    #[Test]
    public function production_compiles(): void
    {
        $compiled = $this->compile("@production\nProduction only\n@endproduction");

        $this->assertStringContainsString("app_env() === 'production'", $compiled);
    }

    // =========================================================================
    // @session / @endsession
    // =========================================================================

    #[Test]
    public function session_compiles(): void
    {
        $compiled = $this->compile("@session('flash')\n{{ \$value }}\n@endsession");

        $this->assertStringContainsString("session()->has('flash')", $compiled);
        $this->assertStringContainsString("session()->get('flash')", $compiled);
    }

    // =========================================================================
    // @pushOnce / @endPushOnce
    // =========================================================================

    #[Test]
    public function pushOnce_compiles(): void
    {
        $compiled = $this->compile("@pushOnce('scripts')\n<script></script>\n@endPushOnce");

        $this->assertStringContainsString("addOnceHash('push_once_scripts_", $compiled);
        $this->assertStringContainsString("startPush('scripts')", $compiled);
        $this->assertStringContainsString("stopPush()", $compiled);
    }

    // =========================================================================
    // @includeIf
    // =========================================================================

    #[Test]
    public function includeIf_compiles(): void
    {
        $compiled = $this->compile("@includeIf('optional.view')");

        $this->assertStringContainsString("render('optional.view')", $compiled);
        $this->assertStringContainsString('viewExists', $compiled);
    }

    // =========================================================================
    // @parent
    // =========================================================================

    #[Test]
    public function parent_compiles_to_placeholder(): void
    {
        $compiled = $this->compile("@parent");

        $this->assertStringContainsString('@@parent_placeholder@@', $compiled);
    }

    // =========================================================================
    // @required
    // =========================================================================

    #[Test]
    public function required_compiles(): void
    {
        $compiled = $this->compile("<input @required(\$isRequired)>");

        $this->assertStringContainsString('if ($isRequired)', $compiled);
        $this->assertStringContainsString('required', $compiled);
    }

    // =========================================================================
    // @use
    // =========================================================================

    #[Test]
    public function use_compiles_with_class(): void
    {
        $compiled = $this->compile("@use('App\\Models\\User')");

        $this->assertStringContainsString('use App\\Models\\User as User', $compiled);
    }

    #[Test]
    public function use_compiles_with_alias(): void
    {
        $compiled = $this->compile("@use('App\\Models\\User', 'AppUser')");

        $this->assertStringContainsString('use App\\Models\\User as AppUser', $compiled);
    }

    // =========================================================================
    // @persist / @endpersist
    // =========================================================================

    #[Test]
    public function persist_compiles_to_div(): void
    {
        $compiled = $this->compile("@persist('sidebar')\n<nav>Sidebar</nav>\n@endpersist");

        $this->assertStringContainsString('id="persist-sidebar"', $compiled);
        $this->assertStringContainsString('data-persist', $compiled);
        $this->assertStringContainsString('</div>', $compiled);
    }

    // =========================================================================
    // @model
    // =========================================================================

    #[Test]
    public function model_compiles_to_phpdoc(): void
    {
        $compiled = $this->compile("@model(App\\Entity\\User)");

        $this->assertStringContainsString('@var \\App\\Entity\\User $model', $compiled);
    }

    // =========================================================================
    // @autoescape / @endautoescape
    // =========================================================================

    #[Test]
    public function autoescape_compiles(): void
    {
        $compiled = $this->compile("@autoescape('js')\n{{ \$config }}\n@endautoescape");

        $this->assertStringContainsString("\$__escapeContext = 'js'", $compiled);
        $this->assertStringContainsString("\$__previousEscapeContext", $compiled);
    }
}
