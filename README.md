# MLView Template Engine

MLView is the built‑in, lightweight template engine for **MonkeysLegion**, designed to help you write clean, component‑driven views with minimal boilerplate.

---

## 🌟 Key Features

* **Escaped output**: `{{ $var }}` → safe, HTML‑escaped data.
* **Raw output**: `{!! $html !!}` → unescaped HTML (use responsibly).
* **Components**: `<x-card title="...">...</x-card>` → reusable view fragments.
* **Slots**: `@slot('header')…@endslot` → named content areas inside components.
* **Layouts**: Wrap views in a layout component, passing attributes + slots.
* **Caching & Hot‑Reload**: Compiled PHP cached in `var/cache/views`; auto‑recompiles changed templates.

---

## 📂 Directory Structure

```
my-app/
├─ resources/views/
│  ├─ home.ml.php              # Top‑level view
│  ├─ posts/
│  │  └─ show.ml.php           # Nested view under posts/
│  └─ components/
│     ├─ layout.ml.php         # Layout component
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
<p>Name: {{ $user->name }}</p>   <!-- escaped -->
<p>Bio: {!! $user->bio !!}</p>   <!-- raw -->
```

### 2. Components

```php
// In a view:
<x-alert type="error">
  <p>Error occurred!</p>
</x-alert>

// resources/views/components/alert.ml.php
<div class="alert alert-<?= $type ?>">
  <?= $slotContent ?>
</div>
```

* **Attributes** (`type="error"`) become PHP variables (`$type`).
* **Inner HTML** captured as `$slotContent`.

### 3. Slots

```php
<x-layout title="Dashboard">
  @slot('header')
    <h1>Dashboard</h1>
  @endslot

  <p>Main content here…</p>
</x-layout>
```

* `@slot('header') … @endslot` compiles into a closure stored in `$slots['header']`.
* Layout calls `<?= $slots['header']() ?>`.

---

## 📝 Complete Example

### A) Layout Component: `resources/views/components/layout.ml.php`

```php
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>{{ $title }}</title>
  <link rel="stylesheet" href="/css/app.css">
</head>
<body>
  <nav>
    <a href="/">Home</a> |
    <a href="/posts">Posts</a>
  </nav>

  <header>
    <?= $slots['header']() ?>
  </header>

  <main>
    <?= $slotContent ?>
  </main>

  <footer>&copy; <?= date('Y') ?> MonkeysLegion</footer>
</body>
</html>
```

### B) Page View: `resources/views/posts/show.ml.php`

```php
<x-layout title="{{ $post->title }}">

  @slot('header')
    <h1>{{ $post->title }}</h1>
    <p><small>By {{ $post->author }}</small></p>
  @endslot

  <article>
    {!! $post->body !!}
  </article>

  <x-alert type="info">
    <p>Published: {{ $post->createdAt->format('Y-m-d') }}</p>
  </x-alert>

</x-layout>
```

### C) Controller Method

```php
public function show(ServerRequestInterface $req): ResponseInterface
{
  $postId = $req->getAttribute('id');
  $post   = \$this->repo->find(\$postId);

  \$html = \$this->view->render('posts.show', [
    'post' => \$post,
  ]);

  return new Response(
    Stream::createFromString(\$html),
    200,
    ['Content-Type' => 'text/html']
  );
}
```

---

## ⚙️ Rendering Pipeline

1. **Loader**: finds raw `.ml.php` + cache path.
2. **Parser**: rewrites `<x-*>` & `@slot` to PHP snippets.
3. **Compiler**: processes directives, escapes `{{ }}`, raw `{!! !!}`, prepends PHP header.
4. **Cache**: saves to `var/cache/views/<name>.php` and `include`s it.

---

## 🔄 Caching & Hot‑Reload

* **Location**: `var/cache/views`
* **Auto‑invalidate**: checks the template timestamp on each render.
* **Manual**: `php vendor/bin/ml cache:clear`

---

## 🔧 Extensibility

* **Custom directives**: add `preg_replace_callback` in `Compiler` (e.g. `@uppercase()`).
* **AST enhancement**: extend `Parser` to build an AST for richer syntax.
* **Custom loaders**: swap `Loader` for DB‑backed or alternative filesystem layouts.

---

## Debugging
* **Debug variable in template**: `@dump($variable)`.

Happy templating with MLView! 🚀
