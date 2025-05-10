# MLView Template Engine

This document covers usage of the MLView engine, including layout components, slots, and nested components.

---

## Overview

MLView is the built‑in template engine for MonkeysLegion. It provides:

- **Escaped output**: `{{ $var }}` renders HTML‑escaped data
- **Raw output**: `{!! $html !!}` renders unescaped HTML
- **Components**: `<x-name>` tags render reusable view fragments
- **Slots**: `@slot('name') … @endslot` define named content areas inside components
- **Layouts**: Wrap content in a layout component, passing attributes and slots

Templates use the `.ml.php` extension and live in `resources/views/`.

---

## Example: Layout and Show View

### Layout: `resources/views/layouts/app.ml.php`

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
</head>
<body>
    <nav>
        <a href="/">Home</a> |
        <a href="/posts">Posts</a>
    </nav>

    <header>
        <!-- The `header` slot content -->
        <?= $slots['header']() ?>
    </header>

    <main>
        <!-- The main view content -->
        <?= $slotContent ?>
    </main>

    <footer>
        &copy; <?= date('Y') ?> MonkeysLegion
    </footer>
</body>
</html>
```

### Show View: `resources/views/posts/show.ml.php`

```php
<x-layout title="{{ $post->title }}">

    @slot('header')
        <h1>{{ $post->title }}</h1>
        <p><small>by {{ $post->author }}</small></p>
    @endslot

    <article>
        <!-- body may contain HTML, so we render it raw -->
        {!! $post->body !!}
    </article>

    <x-alert type="info">
        <p>Published on {{ $post->createdAt->format('Y-m-d') }}</p>
    </x-alert>

</x-layout>
```

## How It Works
1.	Component <x-layout>
•	Finds resources/views/components/layout.ml.php
•	Attributes (title) are available as $title
•	Inner HTML becomes $slotContent
•	Named slots (e.g. header) available in <?= $slots['header']() ?>
2.	Slots
•	@slot('name') … @endslot blocks compile into closures in $slots
•	Layouts invoke them via <?= $slots['name']() ?>
3.	Nested Components
•	<x-alert> renders resources/views/components/alert.ml.php
•	Component templates can reference $type and $slotContent

## Caching & Hot‑Reload
•	Location — compiled templates land in var/cache/views.
•	Invalidation — timestamp check; edit a view file and the next request auto‑compiles again.
•	Clear — MLView::clearCache() (your dev server can call this on file‑watch).

## Extending the Engine
	•	New directives – add regex passes in Compiler.
	•	More advanced AST work – evolve Parser to build ComponentNode / SlotNode trees, then emit PHP in Compiler.
	•	Custom loaders – swap Loader if you want namespaces or database‑stored templates (just implement getPath()).