# MLView Template Engine

MLView is the built‑in, lightweight template engine for **MonkeysLegion**, designed to help you write clean, component‑driven views with minimal boilerplate.

---

## 🌟 Key Features

- **Escaped output**: `{{ $var }}` → safe, HTML‑escaped data
- **Raw output**: `{!! $html !!}` → unescaped HTML (use responsibly)
- **Logic**: `@if`, `@foreach` with `$loop` variable, `@switch`, `@unless`, `@isset`, `@empty`
- **Stacks**: `@stack`, `@push`, `@prepend` for efficient asset management
- **Encryption**: `@inject` for service injection
- **Components**: `<x-card title="...">...</x-card>` → reusable view fragments
- **Slots**: `@slot('header')…@endslot` → named content areas inside components
- **Layout inheritance**: `@extends('parent')`, `@section('name')…@endsection`, `@yield('name')`
- **Caching & Hot‑Reload**: compiled PHP cached in `var/cache/views`; auto‑recompiles modified templates

---

## 📂 Directory Structure

```
my-app/
├─ resources/views/
│  ├─ home.ml.php              # Top‑level view
│  ├─ posts/
│  │  └─ show.ml.php           # Nested view under posts/
│  └─ components/
│     ├─ layout.ml.php         # Layout component for <x-layout>
│     └─ alert.ml.php          # Alert component
└─ var/
   └─ cache/views/             # Generated PHP cache files
```

**Dot‑notation** when rendering:

- `home` → `resources/views/home.ml.php`
- `posts.show` → `resources/views/posts/show.ml.php`

---

## 2 · Hello, World

<!-- resources/views/hello.ml.php -->
<h1>Hello, {{ $name }}!</h1>

use MonkeysLegion\Template\MLView;

$view = new MLView(base_path('resources/views'));

echo $view->render('hello', ['name' => 'Alice']);

The first call parses → compiles → caches the template; subsequent renders are just an include.

---

## 3 · Component Syntax

<x-card>
  <x-slot:title>
    Welcome, {{ $user->name }}
  </x-slot:title>

  <p>Your last login was {{ $user->lastLogin->diffForHumans() }}.</p>
</x-card>

<x-component> ↔ PHP class App\View\Components\Component.

<x-slot:name> fills the component's $this->slot('name').

{{ … }} escapes htmlspecialchars(); {!! … !!} prints raw.

---

## 6 · Blade-style Helpers

| Helper                 | Description                                                                |
| ---------------------- | -------------------------------------------------------------------------- |
| @if / @elseif / @endif | Control blocks                                                             |
| @foreach / @endforeach | Loops (auto-escapes inside {{ }})                                          |
| @include('partial')    | Inlines another template                                                   |
| @csrf                  | Outputs <input type="hidden" …> token (when the CSRF package is installed) |

(All helpers compile down to raw PHP inside the cached file—no runtime cost.)

---

## 🛠️ Component Best Practices

### Simple Component Creation

Components should be straightforward with minimal PHP boilerplate:

```php
<div class="alert alert-<?= $type ?>">
  <?php if (isset($slots['header'])): ?>
    <div class="alert-header"><?= $slots['header']() ?></div>
  <?php endif; ?>
  <div class="alert-body"><?= $slotContent ?></div>
</div>
```

- **Check slots**: Always use `isset($slots['name'])` before accessing slots
- **Access slot content**: Use `$slots['header']()` for named slots or `$slotContent` for default content
- **Component attributes**: All attributes passed to your component are available as PHP variables

### Advanced Component Rendering

Behind the scenes, MLView uses a component rendering pipeline:

1. Slots are processed recursively to handle nested components
2. Component attributes are extracted into the local scope
3. The main content is captured in `$slotContent`
4. Component output is inserted into the parent template

This approach allows for reusable components that maintain proper scoping while keeping them simple.

```blade
@extends('layouts.app')

@section('title')
  {{ \$title }}
@endsection

@section('header')
  <h1>Welcome!</h1>
@endsection

@section('content')
  <p>Home page content…</p>
@endsection
```

- `@extends('layouts.app')` indicates the parent template
- `@section('…')…@endsection` blocks define content
- `@yield('…')` in the parent is replaced by each section

## ⚙️ Rendering Pipeline

1. **Loader**: resolves raw `.ml.php` + cache path
2. **Parser**: transforms `<x-*>`, `@slot`, `@section`, `@yield`, `{{ }}`, `{!! !!}` into an AST
3. **Compiler**: generates pure PHP code from the AST
4. **Cache**: writes to `var/cache/views/<name>.php` and `include`s it

---

## 🔄 Caching & Hot‑Reload

- **Location**: `var/cache/views`
- **Auto‑invalidate**: template timestamp checked on each render
- **Manual clear**: `php vendor/bin/ml cache:clear`

---

## 🔧 Extensibility

- **Custom directives**: add regex callbacks in `Compiler`
- **AST extensions**: enhance `Parser` for new syntax
- **Alternative loaders**: swap `Loader` for custom sources (DB, remote)

---

## Debugging

- **Dump data**: `@dump(\$variable)` inside templates to var_dump

Happy templating with MLView! 🚀

# MLView Component System

The MLView template engine supports a component system to build reusable UI elements.

## Component Usage

Use components in your templates with `<x-name>` tags:

```php
<!-- Using a component with a default slot -->
<x-alert type="warning">
    This is a warning message!
</x-alert>

<!-- Using a component with named slots -->
<x-card>
    @slot('header')
        Card Title
    @endslot

    This is the main content (default slot)

    @slot('footer')
        <button>Action</button>
    @endslot
</x-card>
```

## Creating Components

Components should use PHP syntax for optimal compatibility:

```php
<!-- resources/views/components/alert.ml.php -->
@param(['type' => 'info', 'dismissible' => false])

<div class="alert alert-<?= $type ?> <?= $dismissible ? 'alert-dismissible' : '' ?>">
    <?php if (isset($slots['header'])): ?>
        <div class="alert-header"><?= $slots['header']() ?></div>
    <?php endif; ?>

    <div class="alert-body"><?= $slotContent ?></div>

    <?php if ($dismissible): ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    <?php endif; ?>
</div>
```

### Component Parameters

Components can define default parameters using the `@param` directive:

```php
@param(['name' => 'default value', 'another' => true, 'count' => 5])
```

These parameters:

- Must be defined at the top of the component file
- Will be available as PHP variables inside the component
- Can be overridden by passing attributes to the component

When using the component:

```php
<!-- Uses default 'info' type -->
<x-alert>This is an info message</x-alert>

<!-- Overrides the default type with 'danger' -->
<x-alert type="danger">This is a danger alert!</x-alert>

<!-- Sets dismissible to true -->
<x-alert type="warning" dismissible="true">This is dismissible</x-alert>
```

The parameters system allows component authors to define sensible defaults while giving component users the flexibility to customize as needed.

## Slot Handling

Define slots using Blade-style `@slot` directives:

```php
<x-card>
    @slot('header')
        Card Title
    @endslot

    Default content
</x-card>
```

## Component Data

Inside a component:

- `$slotContent` contains the default (unnamed) slot content
- `$slots['name']()` calls and renders named slots
- All attributes from the component tag are available as variables

## Template Inclusion

### @include directive

The `@include` directive lets you include one template from another:

```php
<!-- Include a partial template -->
@include('partials.header')

<!-- Include with variables -->
@include('partials.alert', ['type' => 'warning', 'message' => 'Danger ahead!'])
```

Templates included with `@include` should be placed in the standard views directory structure and are referenced using dot notation:

- `@include('header')` → `resources/views/header.ml.php`
- `@include('partials.header')` → `resources/views/partials/header.ml.php`

### Component vs @include

- **Components** (`<x-name>`) are placed in `resources/views/components/name.ml.php`
- **Included templates** (`@include`) can be placed anywhere in the views directory
- Components support slots and have a dedicated lifecycle
- Included templates are simply merged into the parent template

---

## 7 · Stacks & Layouts

Push content to named stacks from anywhere in your view hierarchy, perfect for injecting scripts or styles from child views.

```php
<!-- In layout -->
<head>
    @stack('styles')
</head>
<body>
    @stack('scripts')
</body>

<!-- In child view -->
@push('scripts')
    <script src="app.js"></script>
@endpush

@prepend('styles')
    <style>body { background: #333; }</style>
@endprepend
```

---

## 8 · Service Injection

Inject services directly into your templates using `@inject`:

```php
@inject('metrics', 'App\Services\MetricsService')

<div>
    Monthly Visits: {{ $metrics->getMonthlyVisits() }}
</div>
```

---

## 9 · The Loop Variable

Inside `@foreach` loops, a `$loop` variable is automatically available to track iteration state:

```php
@foreach($users as $user)
    @if($loop->first)
        Start of list
    @endif

    {{ $loop->iteration }} - {{ $user->name }}

    @if($loop->last)
        End of list
    @endif
@endforeach
```

Properties available: `index`, `iteration`, `remaining`, `count`, `first`, `last`, `even`, `odd`, `depth`, `parent`.

---

## 10 · Conditional Sugar

Use shorthand directives for clearer intent:

```php
@unless($isAdmin)
    You are not an admin.
@endunless

@isset($records)
    // $records is defined and not null
@endisset

@empty($records)
    // $records is empty
@endempty

@switch($i)
    @case(1)
        First case...
    @break
    @default
        Default case...
@endswitch
```

---

## 11 · Advanced Includes

Conditionally include views to keep templates clean:

```php
@includeWhen($isLoggedIn, 'nav.user-menu')
@includeUnless($isAdmin, 'nav.guest-menu')
@includeFirst(['custom.admin', 'admin.dashboard'], ['data' => $data])
```

---

## 12 · Raw PHP/JS

Use `@verbatim` to prevent MLView from parsing Blade-like syntax (useful for Vue/React apps):

```php
@verbatim
    <div id="app">
        Hello {{ name }} <!-- This will be ignored by MLView -->
    </div>
@endverbatim
```
