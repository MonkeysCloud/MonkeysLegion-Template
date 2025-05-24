# MLView Template Engine

MLView is the built‑in, lightweight template engine for **MonkeysLegion**, designed to help you write clean, component‑driven views with minimal boilerplate.

---

## 🌟 Key Features

* **Escaped output**: `{{ $var }}` → safe, HTML‑escaped data
* **Raw output**: `{!! $html !!}` → unescaped HTML (use responsibly)
* **Components**: `<x-card title="...">...</x-card>` → reusable view fragments
* **Slots**: `@slot('header')…@endslot` → named content areas inside components
* **Layout inheritance**: `@extends('parent')`, `@section('name')…@endsection`, `@yield('name')`
* **Caching & Hot‑Reload**: compiled PHP cached in `var/cache/views`; auto‑recompiles modified templates

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

* `home` → `resources/views/home.ml.php`
* `posts.show` → `resources/views/posts/show.ml.php`

---

## ✏️ Basic Syntax

### 1. Echoing Data

```php
<p>Name: {{ \$user->name }}</p>   <!-- escaped -->
<p>Bio: {!! \$user->bio !!}</p>   <!-- raw -->
```

### 2. Components & Slots

```php
<x-alert type="error">
  @slot('header')
    <strong>Error:</strong>
  @endslot
  <p>Something went wrong.</p>
</x-alert>
```

`resources/views/components/alert.ml.php`:

```php
<div class="alert alert-<?= \$type ?>">
  <?= \$slots['header']() ?>
  <?= \$slotContent ?>
</div>
```

* **Attributes** (`type="error"`) become PHP variables (`\$type`).
* **Named slots** captured as closures in `\$slots['name']`.
* **Default slot** content available as `\$slotContent`.

### 3. Layout Components

```php
<x-layout title="Dashboard">
  @slot('header')
    <h1>Dashboard</h1>
  @endslot

  <p>Main content…</p>
</x-layout>
```

`resources/views/components/layout.ml.php` might include `<?= \$slots['header']() ?>` and `<?= \$slotContent ?>` zones.

### 4. Layout Inheritance

**Parent layout** (`resources/views/layouts/app.ml.php`):

```html
<!DOCTYPE html>
<html>
<head>
  <title>@yield('title')</title>
</head>
<body>
  <header>@yield('header')</header>
  <main>@yield('content')</main>
  <footer>© {{ date('Y') }} MonkeysLegion</footer>
</body>
</html>
```

**Child view** (`resources/views/home.ml.php`):

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

* `@extends('layouts.app')` indicates the parent template
* `@section('…')…@endsection` blocks define content
* `@yield('…')` in the parent is replaced by each section

---

## ⚙️ Rendering Pipeline

1. **Loader**: resolves raw `.ml.php` + cache path
2. **Parser**: transforms `<x-*>`, `@slot`, `@section`, `@yield`, `{{ }}`, `{!! !!}` into an AST
3. **Compiler**: generates pure PHP code from the AST
4. **Cache**: writes to `var/cache/views/<name>.php` and `include`s it

---

## 🔄 Caching & Hot‑Reload

* **Location**: `var/cache/views`
* **Auto‑invalidate**: template timestamp checked on each render
* **Manual clear**: `php vendor/bin/ml cache:clear`

---

## 🔧 Extensibility

* **Custom directives**: add regex callbacks in `Compiler`
* **AST extensions**: enhance `Parser` for new syntax
* **Alternative loaders**: swap `Loader` for custom sources (DB, remote)

---

## Debugging

* **Dump data**: `@dump(\$variable)` inside templates to var\_dump

Happy templating with MLView! 🚀
