# MLView Template Engine

**MLView** is the built‑in, high‑performance template engine for **MonkeysLegion**, designed for clean, component‑driven views with minimal boilerplate. Inspired by Blade, Twig, Jinja2, and Phoenix LiveView — with multi-tier caching and 0.015 ms/render performance.

```
monkeyscloud/monkeyslegion-template v2.0
PHP 8.4+ | MIT License
476 tests | 902 assertions | PHPStan Level 8
```

---

## Table of Contents

1. [Installation](#installation)
2. [Quick Start](#quick-start)
3. [Output & Echoes](#output--echoes)
4. [Filters (Pipe Syntax)](#filters-pipe-syntax)
5. [Control Structures](#control-structures)
6. [Loops & The `$loop` Variable](#loops--the-loop-variable)
7. [Components & Slots](#components--slots)
8. [Layout Inheritance](#layout-inheritance)
9. [Stacks & Asset Management](#stacks--asset-management)
10. [Template Inclusion](#template-inclusion)
11. [Frontend Helpers](#frontend-helpers)
12. [Form Helpers](#form-helpers)
13. [Framework Utilities](#framework-utilities)
14. [Advanced Directives](#advanced-directives)
15. [Fragment Caching](#fragment-caching)
16. [Template Macros](#template-macros)
17. [Element-Level Directives (HEEx-Style)](#element-level-directives-heex-style)
18. [Namespaces & Theming](#namespaces--theming)
19. [View Composers & Events](#view-composers--events)
20. [Streaming & Async](#streaming--async)
21. [Security](#security)
22. [Caching Architecture](#caching-architecture)
23. [Performance](#performance)
24. [Testing](#testing)
25. [CLI Tooling](#cli-tooling)
26. [Extensibility](#extensibility)

---

## Installation

```bash
composer require monkeyscloud/monkeyslegion-template
```

**Optional dependencies:**

| Package | Purpose |
|---------|---------|
| `psr/simple-cache` | PSR-16 cache backend (Redis/Memcached) |
| `monkeyscloud/monkeyslegion-cache` | Full-featured caching with tags & locks |
| `monkeyscloud/monkeyslegion-di` | Class-based component DI |
| `monkeyscloud/monkeyslegion-cli` | CLI commands (`view:compile`, `lint`) |

---

## Quick Start

### Directory Structure

```
my-app/
├─ resources/views/
│  ├─ home.ml.php              # Top-level view
│  ├─ posts/
│  │  └─ show.ml.php           # Nested: posts.show
│  ├─ layouts/
│  │  └─ app.ml.php            # Layout template
│  └─ components/
│     ├─ alert.ml.php          # <x-alert> component
│     └─ card.ml.php           # <x-card> component
└─ var/cache/views/             # Compiled PHP (auto-generated)
```

### Hello World

```php
// resources/views/hello.ml.php
<h1>Hello, {{ $name }}!</h1>
```

```php
use MonkeysLegion\Template\{Loader, Parser, Compiler, Renderer, MLView};

$loader   = new Loader('resources/views', 'var/cache/views');
$parser   = new Parser();
$compiler = new Compiler($parser);
$renderer = new Renderer($parser, $compiler, $loader, true, 'var/cache/views');

$view = new MLView($loader, $compiler, $renderer, 'var/cache/views');

echo $view->render('hello', ['name' => 'Alice']);
// Output: <h1>Hello, Alice!</h1>
```

First call: parse → compile → cache. Subsequent calls: include cached PHP directly.

**Dot-notation** resolves paths:
- `home` → `resources/views/home.ml.php`  
- `posts.show` → `resources/views/posts/show.ml.php`

---

## Output & Echoes

### Escaped Output

```php
{{ $name }}                    {{-- HTML-escaped: <script> → &lt;script&gt; --}}
{{ $user->name }}              {{-- Object property --}}
{{ $count > 0 ? 'Yes' : 'No' }}  {{-- Expressions --}}
```

### Raw Output

```php
{!! $trustedHtml !!}           {{-- Unescaped — use responsibly --}}
```

### Comments

```php
{{-- This comment is removed from compiled output --}}
```

### PHP Blocks

```php
@php
    $total = array_sum($prices);
@endphp
```

---

## Filters (Pipe Syntax)

Inspired by Twig/Jinja2. Apply transformations via `|`:

```php
{{ $name | upper }}                          {{-- ALICE --}}
{{ $name | lower | capitalize }}             {{-- Alice --}}
{{ $text | truncate(50, '...') }}            {{-- First 50 chars... --}}
{{ $price | number(2, '.', ',') }}           {{-- 1,234.56 --}}
{{ $items | pluck('name') | join(', ') }}    {{-- Item1, Item2, Item3 --}}
{{ $html | raw }}                            {{-- Skip escaping --}}
```

### Built-in Filters (35+)

| Category | Filters |
|----------|---------|
| **String** | `upper`, `lower`, `capitalize`, `title`, `trim`, `length`, `reverse`, `repeat`, `replace`, `split`, `slug`, `nl2br`, `truncate` |
| **Number** | `number`, `abs`, `max`, `min` |
| **Array** | `join`, `first`, `last`, `count`, `sort`, `keys`, `values`, `unique`, `flatten`, `chunk`, `pluck`, `merge` |
| **Date** | `date` — works with `DateTime`, timestamps, and strings |
| **Encoding** | `json`, `e`, `escape`, `raw` |
| **Utility** | `default`, `prepend`, `append`, `wrap`, `pad`, `wordcount`, `excerpt`, `batch`, `map`, `filter`, `sum`, `avg` |

### Examples

```php
{{-- Date formatting --}}
{{ $createdAt | date('M d, Y') }}            {{-- Jan 15, 2026 --}}

{{-- Default values --}}
{{ $nickname | default('Anonymous') }}

{{-- Array manipulation --}}
{{ $users | pluck('email') | unique | count }}

{{-- Chaining --}}
{{ $bio | truncate(100) | nl2br }}

{{-- JSON output --}}
{{ $config | json }}
```

### Custom Filters

```php
$view->addFilter('currency', fn($v) => '$' . number_format($v, 2));
// Usage: {{ $price | currency }}
```

---

## Control Structures

### Conditionals

```php
@if($user->isAdmin())
    <span class="badge">Admin</span>
@elseif($user->isModerator())
    <span class="badge">Mod</span>
@else
    <span class="badge">User</span>
@endif
```

### Conditional Sugar

```php
@unless($isAdmin)
    You are not an admin.
@endunless

@isset($records)
    Found {{ count($records) }} records.
@endisset

@empty($results)
    No results found.
@endempty
```

### Switch

```php
@switch($status)
    @case('active')
        <span class="green">Active</span>
    @break
    @case('pending')
        <span class="yellow">Pending</span>
    @break
    @default
        <span class="gray">Unknown</span>
@endswitch
```

---

## Loops & The `$loop` Variable

### `@foreach`

Every `@foreach` provides a `$loop` variable with iteration metadata:

```php
@foreach($users as $user)
    @if($loop->first)
        <div class="first-item">
    @endif

    <p>{{ $loop->iteration }}. {{ $user->name }}</p>

    @if($loop->last)
        </div>
    @endif
@endforeach
```

**`$loop` properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$loop->index` | `int` | Zero-based index |
| `$loop->iteration` | `int` | One-based index |
| `$loop->remaining` | `int` | Items remaining |
| `$loop->count` | `int` | Total items |
| `$loop->first` | `bool` | Is first iteration |
| `$loop->last` | `bool` | Is last iteration |
| `$loop->even` | `bool` | Is even iteration |
| `$loop->odd` | `bool` | Is odd iteration |
| `$loop->depth` | `int` | Nesting depth (1+) |
| `$loop->parent` | `?Loop` | Parent loop (nested) |

### `@forelse`

```php
@forelse($posts as $post)
    <h2>{{ $post->title }}</h2>
@empty
    <p>No posts found.</p>
@endforelse
```

### `@for` / `@while`

```php
@for($i = 0; $i < 10; $i++)
    {{ $i }}
@endfor

@while($condition)
    Processing...
@endwhile
```

### Loop Control

```php
@foreach($items as $item)
    @if($item->hidden)
        @continue
    @endif
    @if($loop->iteration > 5)
        @break
    @endif
    {{ $item->name }}
@endforeach
```

---

## Components & Slots

### Using Components

```php
{{-- Simple component --}}
<x-alert type="warning">
    Watch out!
</x-alert>

{{-- Self-closing --}}
<x-badge text="Active" color="green" />

{{-- With named slots --}}
<x-card>
    <x-slot:header>
        Card Title
    </x-slot:header>

    Main content goes here.

    <x-slot:footer>
        <button>Save</button>
    </x-slot:footer>
</x-card>
```

### Creating Components

```php
{{-- resources/views/components/alert.ml.php --}}
@param(['type' => 'info', 'dismissible' => false])

<div class="alert alert-{{ $type }}" {{ $attributes }}>
    @if($slots->has('header'))
        <strong>{{ $slots->header }}</strong>
    @endif

    <div class="alert-body">{{ $slot }}</div>

    @if($dismissible)
        <button class="btn-close" data-dismiss="alert"></button>
    @endif
</div>
```

### Function Components

Lightweight, closure-based components — no template file needed:

```php
$view->component('badge', fn(string $text, string $color = 'blue') =>
    "<span class=\"badge bg-{$color}\">" . htmlspecialchars($text) . "</span>"
);

// Usage: <x-badge text="New" color="green" />
```

### Attribute Bag

All extra attributes are collected in `$attributes`:

```php
{{-- Component template --}}
<div {{ $attributes->merge(['class' => 'card']) }}>
    {{ $slot }}
</div>

{{-- Usage --}}
<x-card class="shadow-lg" id="my-card">Content</x-card>
{{-- Output: <div class="card shadow-lg" id="my-card">Content</div> --}}
```

### Component Data

Inside a component:

| Variable | Description |
|----------|-------------|
| `$slot` | Default slot content |
| `$slots->name` | Named slot content |
| `$slots->has('name')` | Check if named slot exists |
| `$attributes` | AttributeBag with all passed attributes |
| `@aware(['key' => 'default'])` | Access parent component data |

---

## Layout Inheritance

### Parent Layout

```php
{{-- resources/views/layouts/app.ml.php --}}
<!DOCTYPE html>
<html>
<head>
    <title>@yield('title', 'My App')</title>
    @stack('styles')
</head>
<body>
    <nav>@yield('nav')</nav>

    <main>
        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
```

### Child View

```php
{{-- resources/views/home.ml.php --}}
@extends('layouts.app')

@section('title')
    Home Page
@endsection

@section('content')
    <h1>Welcome!</h1>
    <p>This is the home page.</p>
@endsection

@push('scripts')
    <script src="/js/home.js"></script>
@endpush
```

---

## Stacks & Asset Management

Push CSS/JS from any child view or component into named stacks in the layout:

```php
{{-- In layout --}}
<head>
    @stack('styles')
</head>
<body>
    @yield('content')
    @stack('scripts')
</body>

{{-- In child view or component --}}
@push('scripts')
    <script src="app.js"></script>
@endpush

@prepend('styles')
    <link rel="stylesheet" href="critical.css">
@endprepend

{{-- Push once (dedup) --}}
@pushOnce('scripts')
    <script src="shared-lib.js"></script>
@endPushOnce
```

---

## Template Inclusion

```php
{{-- Basic include --}}
@include('partials.header')

{{-- Include with data --}}
@include('partials.alert', ['type' => 'warning', 'message' => 'Heads up!'])

{{-- Conditional includes --}}
@includeWhen($isLoggedIn, 'nav.user-menu')
@includeUnless($isAdmin, 'nav.guest-menu')

{{-- Include first match --}}
@includeFirst(['custom.admin', 'admin.dashboard'], ['data' => $data])

{{-- Include if exists --}}
@includeIf('optional.sidebar')
```

---

## Frontend Helpers

| Directive | Example | Output |
|-----------|---------|--------|
| `@json($data)` | `@json($config)` | Safe JSON in HTML |
| `@js($data)` | `@js($settings)` | JS-safe (unescaped unicode) |
| `@class([...])` | `@class(['btn', 'active' => $isActive])` | `class="btn active"` |
| `@style([...])` | `@style(['color: red' => $isError])` | `style="color: red"` |

```php
<button @class(['btn', 'btn-primary' => $isPrimary, 'disabled' => !$enabled])>
    Submit
</button>

<div @style(['background: red' => $hasError, 'font-weight: bold' => $important])>
    Content
</div>

<script>
    const config = @json($appConfig);
</script>
```

---

## Form Helpers

```php
<form method="POST" action="/users">
    @csrf
    @method('PUT')

    <input type="text" name="name" value="@old('name', $user->name)">

    <input type="checkbox" @checked($user->isAdmin)>

    <select>
        @foreach($roles as $role)
            <option value="{{ $role }}" @selected($role === $currentRole)>
                {{ $role }}
            </option>
        @endforeach
    </select>

    <input type="text" @disabled(!$canEdit)>
    <textarea @readonly($isLocked)></textarea>

    @error('name')
        <span class="error">{{ $message }}</span>
    @enderror
</form>
```

---

## Framework Utilities

### Environment Checks

```php
@env('production')
    {{-- Only in production --}}
    <script src="analytics.js"></script>
@endenv

@production
    {{-- Shorthand for @env('production') --}}
@endproduction
```

### Authentication

```php
@auth
    Welcome, {{ auth()->user()->name }}!
@endauth

@guest
    <a href="/login">Login</a>
@endguest
```

### Authorization

```php
@can('edit', $post)
    <a href="/posts/{{ $post->id }}/edit">Edit</a>
@endcan

@cannot('delete', $post)
    <span class="text-muted">Cannot delete</span>
@endcannot
```

### Session

```php
@session('success')
    <div class="alert alert-success">{{ $value }}</div>
@endsession
```

### Service Injection

```php
@inject('metrics', 'App\Services\MetricsService')

<div>Monthly visits: {{ $metrics->getMonthlyVisits() }}</div>
```

---

## Advanced Directives

### HTMX Fragment Rendering

Render only a fragment for HTMX partial updates:

```php
<div id="user-list">
    @fragment('user-table')
        <table>
            @foreach($users as $user)
                <tr><td>{{ $user->name }}</td></tr>
            @endforeach
        </table>
    @endfragment
</div>
```

### Teleport

Move content to a different DOM location (like Vue's `<Teleport>`):

```php
@teleport('#modals')
    <div class="modal">Modal content</div>
@endteleport
```

### Persist (HTMX)

Mark elements that should persist across HTMX morph-merges:

```php
@persist('audio-player')
    <audio id="player" src="{{ $track->url }}"></audio>
@endpersist
```

### Model Type Hints (IDE Support)

```php
@model(App\Entity\User)
{{-- IDE autocomplete now knows $model is a User --}}
<h1>{{ $model->name }}</h1>
```

### Once

```php
@once
    <script src="shared-component.js"></script>
@endonce
```

### Verbatim

Prevent MLView from parsing a block (useful for JS frameworks):

```php
@verbatim
    <div id="vue-app">
        {{ vueVariable }}
    </div>
@endverbatim
```

### Autoescape

Set the escaping context for a block:

```php
@autoescape('js')
    var name = {{ $name }};
@endautoescape
```

---

## Fragment Caching

Cache expensive template fragments with PSR-16. Requires a cache backend.

```php
{{-- Cache sidebar for 5 minutes --}}
@cache('sidebar-' . $userId, 300)
    <div class="sidebar">
        @foreach($expensiveQuery as $item)
            <p>{{ $item->name }}</p>
        @endforeach
    </div>
@endcache

{{-- Cache forever (no TTL) --}}
@cache('static-nav')
    <nav>...</nav>
@endcache
```

**Setup:**

```php
$view = new MLView($loader, $compiler, $renderer, $cacheDir, [
    'cache' => $redisCache, // Psr\SimpleCache\CacheInterface
]);
```

When no cache backend is configured, `@cache` blocks render normally with zero overhead.

---

## Template Macros

Define reusable template snippets:

```php
{{-- Define a macro --}}
@macro('statusBadge', $status, $label)
    <span class="badge badge-{{ $status }}">{{ $label }}</span>
@endmacro

{{-- Use the macro --}}
@call('statusBadge', 'success', 'Active')
@call('statusBadge', 'danger', 'Inactive')
```

---

## Element-Level Directives (HEEx-Style)

Inspired by Phoenix LiveView — attach directives directly to HTML elements:

```php
{{-- Conditional rendering --}}
<div :if="$showBanner" class="banner">Welcome!</div>

{{-- Negated conditional --}}
<p :unless="$isAdmin">You are not authorized.</p>

{{-- Loop rendering --}}
<li :for="$items as $item">{{ $item->name }}</li>
```

Compiles to standard PHP control structures wrapping the element.

---

## Namespaces & Theming

### View Namespaces

Organize views by package or module:

```php
$view->addNamespace('ui', __DIR__ . '/vendor/ui-lib/views');

// Usage: ui::alert → /vendor/ui-lib/views/alert.ml.php
echo $view->render('ui::alert', ['type' => 'info']);
```

### Theming System

```php
// 1. Multiple view paths (checked in order)
$view->addViewPath('/path/to/overrides');

// 2. Theme activation (prepends theme path)
$view->setTheme('dark');
// Checks: themes/dark/home.ml.php → resources/views/home.ml.php

// 3. Namespace overrides in themes
// themes/dark/vendor/ui/alert.ml.php overrides ui::alert
```

---

## View Composers & Events

### View Composers

Automatically attach data to views by pattern:

```php
// Attach $categories to all 'shop.*' views
$view->composer('shop.*', function (ViewData $data) {
    $data->set('categories', Category::all());
});

// Multiple patterns
$view->composer(['layouts.*', 'partials.nav'], function (ViewData $data) {
    $data->set('menuItems', Menu::forUser(auth()->user()));
});
```

### Shared Data

```php
$view->share('appName', 'MonkeysCloud');
// $appName available in ALL templates
```

### Lifecycle Events

```php
// Before render
$view->rendering(function (ViewRendering $event) {
    // $event->name, $event->data
    $event->data['renderTime'] = microtime(true);
});

// After render
$view->rendered(function (ViewRendered $event) {
    // $event->name, $event->data, $event->output
    Log::info("Rendered {$event->name}");
});
```

---

## Streaming & Async

Render templates as a stream of chunks for progressive HTML delivery:

```php
$view = new MLView($loader, $compiler, $renderer, $cacheDir);

foreach ($view->stream('dashboard', $data) as $chunk) {
    echo $chunk;
    flush();
}
```

### Render String (No File)

```php
$html = $view->renderString('Hello {{ $name }}!', ['name' => 'World']);
```

---

## Security

### Context-Aware Escaping

```php
<a href="@escape('url', $link)"
   onclick="alert(@escape('js', $message))">
    @escape('html', $text)
</a>
```

Contexts: `html`, `js`, `url`, `css`, `attr`

### Strict Mode

Warn on raw `{!! !!}` usage — useful for security audits:

```php
$view = new MLView($loader, $compiler, $renderer, $cacheDir, [
    'strict_mode' => true,
]);
```

### Default Escaping

All `{{ }}` output is escaped via `htmlspecialchars()` with `ENT_QUOTES` and `UTF-8`. Use `{!! !!}` or the `| raw` filter only for trusted content.

---

## Caching Architecture

MLView uses a 3-tier caching system:

```
┌─────────────────────────────────────────────────────┐
│  L1: In-Memory Pool (CompiledTemplatePool)          │
│  Per-request dedup — avoids filemtime on repeats    │
├─────────────────────────────────────────────────────┤
│  L2: View Cache (ViewCacheInterface)                │
│  FilesystemViewCache — atomic writes, OPcache-aware │
│  Psr16ViewCache — Redis/Memcached adapter           │
├─────────────────────────────────────────────────────┤
│  L3: Fragment Cache (@cache directive)              │
│  PSR-16 store for expensive template blocks         │
└─────────────────────────────────────────────────────┘
```

### Configuration

```php
// Development (default) — checks filemtime, auto-recompiles
$view = new MLView($loader, $compiler, $renderer, $cacheDir);

// Production — skip filemtime checks, max speed
$view = new MLView($loader, $compiler, $renderer, $cacheDir, [
    'production' => true,
]);

// Full-featured — PSR-16 backend + fragment caching
$view = new MLView($loader, $compiler, $renderer, $cacheDir, [
    'production' => true,
    'cache'      => $redisCache, // Psr\SimpleCache\CacheInterface
]);
```

### Cache Adapters

| Adapter | Use Case |
|---------|----------|
| `FilesystemViewCache` | Default. Atomic writes, OPcache invalidation, dependency tracking |
| `Psr16ViewCache` | Redis/Memcached via any PSR-16 implementation |
| Custom | Implement `ViewCacheInterface` |

### Cache Commands

```bash
# Clear all compiled templates
$view->clearCache();

# Or via CLI
./bin/mlview cache:clear
```

---

## Performance

### Benchmarks (PHP 8.5, Apple Silicon)

| Operation | Time | Notes |
|-----------|------|-------|
| Simple render (1000×) | **0.015 ms/render** | L1 pool hit |
| Loop render 100 items (500×) | **0.038 ms/render** | With `$loop` variable |
| Compilation (1000×) | **0.013 ms/compile** | With early-exit optimization |
| Filter pipeline (1000×) | **0.025 ms/render** | Multiple chained filters |
| Cache freshness check (10000×) | **0.004 ms/check** | filemtime comparison |
| Large table (1000×10) | **3.0 ms** | 342 KB output |
| Production vs Dev mode | **13.8× speedup** | Skip filemtime checks |

### Optimizations Applied

- **Early-exit guards**: 40+ directive compilers skipped via `str_contains()` when not present
- **Cached FilterRegistry**: single instance shared across all expressions
- **Single-pass simple directives**: 11 no-arg directives compiled in one regex
- **L1 in-memory pool**: eliminates repeated filemtime calls within same request
- **Atomic writes**: `file_put_contents()` with `LOCK_EX` for safe concurrent access
- **OPcache integration**: `opcache_invalidate()` on recompile for immediate effect

---

## Testing

### Test Utilities

```php
use MonkeysLegion\Template\Testing\TestView;

$result = $view->test('dashboard', ['user' => $user]);

$result->assertSee('Welcome');
$result->assertDontSee('Error');
$result->assertSeeInOrder(['Header', 'Content', 'Footer']);
```

### Running Tests

```bash
composer test                 # All 476 tests
composer test:unit           # Unit tests only
composer test:integration    # Integration tests only
composer test:perf           # Performance benchmarks
composer phpstan             # Static analysis (Level 8)
composer check               # CS + PHPStan + Tests
```

---

## CLI Tooling

### Template Linting

```bash
# Lint the default views directory
./bin/mlview lint resources/views

# Check multiple paths
./bin/mlview lint resources/views,modules/blog/views
```

Checks for:
- Missing components (`<x-component>`)
- Missing included views (`@include`)
- Syntax errors
- Unclosed directives

Non-zero exit code on errors — CI/CD ready.

### Pre-compilation

```bash
# Compile all templates ahead of time (deploy step)
./bin/mlview view:compile resources/views
```

---

## Extensibility

### Custom Directives

```php
$view->addDirective('datetime', function ($expression) {
    return "<?php echo date('Y-m-d H:i:s', {$expression}); ?>";
});
// Usage: @datetime($timestamp)
```

### Custom Filters

```php
$view->addFilter('initials', function (string $name): string {
    return implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $name)));
});
// Usage: {{ $fullName | initials }}  →  "JD"
```

### Custom Cache Adapter

```php
use MonkeysLegion\Template\Cache\ViewCacheInterface;

class MyCache implements ViewCacheInterface
{
    public function isFresh(string $name, string $sourcePath, string $compiledPath): bool { /* ... */ }
    public function put(string $compiledPath, string $compiledPhp): void { /* ... */ }
    public function getCompiledPath(string $name, string $sourcePath): string { /* ... */ }
    public function forget(string $name): void { /* ... */ }
    public function flush(): void { /* ... */ }
}

$renderer->setViewCache(new MyCache());
```

### Pipeline Extension Points

| Extension | How |
|-----------|-----|
| Custom directives | `$view->addDirective()` |
| Custom filters | `$view->addFilter()` |
| Function components | `$view->component()` |
| Custom cache | Implement `ViewCacheInterface` |
| Custom loader | Implement `LoaderInterface` |
| View composers | `$view->composer()` |
| Render events | `$view->rendering()` / `$view->rendered()` |

---

## Complete Directive Reference

### Output

| Syntax | Description |
|--------|-------------|
| `{{ $var }}` | Escaped output |
| `{!! $var !!}` | Raw output |
| `{{ $var \| filter }}` | Filtered output |
| `{{-- comment --}}` | Template comment (stripped) |

### Control Flow

| Directive | Description |
|-----------|-------------|
| `@if` / `@elseif` / `@else` / `@endif` | Conditionals |
| `@unless` / `@endunless` | Negated if |
| `@isset` / `@endisset` | Variable existence check |
| `@empty` / `@endempty` | Empty check |
| `@switch` / `@case` / `@default` / `@endswitch` | Switch |
| `@foreach` / `@endforeach` | Loop with `$loop` variable |
| `@forelse` / `@empty` / `@endforelse` | Loop with empty fallback |
| `@for` / `@endfor` | C-style for loop |
| `@while` / `@endwhile` | While loop |
| `@break` / `@continue` | Loop control |

### Components & Layout

| Directive | Description |
|-----------|-------------|
| `<x-name>` / `<x-name />` | Component usage |
| `<x-slot:name>` | Named slot |
| `@slot('name')` / `@endslot` | Slot (alternative syntax) |
| `@param([...])` | Component defaults |
| `@aware([...])` | Parent component data |
| `@extends('layout')` | Layout inheritance |
| `@section('name')` / `@endsection` | Section definition |
| `@yield('name', 'default')` | Section output |
| `@parent` | Insert parent section content |

### Template Composition

| Directive | Description |
|-----------|-------------|
| `@include('view', [...])` | Include template |
| `@includeIf('view')` | Include if exists |
| `@includeWhen($cond, 'view')` | Conditional include |
| `@includeUnless($cond, 'view')` | Negated conditional include |
| `@includeFirst(['a', 'b'])` | First available |
| `@inject('var', 'Class')` | Service injection |

### Stacks

| Directive | Description |
|-----------|-------------|
| `@stack('name')` | Output stack |
| `@push('name')` / `@endpush` | Append to stack |
| `@prepend('name')` / `@endprepend` | Prepend to stack |
| `@pushOnce('name')` / `@endPushOnce` | Deduplicated push |

### Frontend

| Directive | Description |
|-----------|-------------|
| `@json($data)` | JSON encode |
| `@js($data)` | JS-safe encode |
| `@class([...])` | Conditional CSS classes |
| `@style([...])` | Conditional inline styles |
| `@checked($cond)` | Checked attribute |
| `@selected($cond)` | Selected attribute |
| `@disabled($cond)` | Disabled attribute |
| `@readonly($cond)` | Readonly attribute |
| `@required` | Required attribute |

### Forms & Security

| Directive | Description |
|-----------|-------------|
| `@csrf` | CSRF token field |
| `@method('PUT')` | HTTP method spoofing |
| `@error('field')` / `@enderror` | Validation errors |
| `@old('field', 'default')` | Old input value |
| `@escape('context', $var)` | Context-aware escaping |

### Framework

| Directive | Description |
|-----------|-------------|
| `@env('name')` / `@endenv` | Environment check |
| `@production` / `@endproduction` | Production shorthand |
| `@auth` / `@endauth` | Authenticated check |
| `@guest` / `@endguest` | Guest check |
| `@can('ability', $model)` / `@endcan` | Authorization |
| `@cannot('ability', $model)` / `@endcannot` | Authorization negated |
| `@session('key')` / `@endsession` | Session check |
| `@hasSection('name')` | Check section exists |
| `@sectionMissing('name')` | Check section missing |

### Advanced

| Directive | Description |
|-----------|-------------|
| `@cache('key', ttl)` / `@endcache` | Fragment caching |
| `@macro('name', ...)` / `@endmacro` | Define macro |
| `@call('name', ...)` | Invoke macro |
| `@fragment('name')` / `@endfragment` | HTMX partial rendering |
| `@teleport('selector')` / `@endteleport` | Content teleport |
| `@persist('id')` / `@endpersist` | HTMX persist |
| `@model(Class)` | IDE type hint |
| `@autoescape('context')` / `@endautoescape` | Block escaping |
| `@options([...])` | Template options |
| `@once` / `@endonce` | Render once |
| `@verbatim` / `@endverbatim` | Skip parsing |
| `@php` / `@endphp` | Raw PHP block |
| `@use('Class')` | Import class |

### Element-Level (HEEx-Style)

| Attribute | Description |
|-----------|-------------|
| `:if="$condition"` | Conditional element |
| `:unless="$condition"` | Negated conditional |
| `:for="$items as $item"` | Loop element |

### Debugging

| Directive | Description |
|-----------|-------------|
| `@dump($var)` | Formatted var_dump |
| `@dd($var)` | Dump and die |

---

## License

MIT © MonkeysCloud
