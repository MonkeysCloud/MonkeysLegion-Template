# MLView Future Syntax & Roadmap

This document outlines the planned syntax additions and architectural improvements for the MLView template engine.

## Upcoming Directives

### 1. `@forelse`
Combines a loop with an empty-state fallback. No more manual `count()` checks.
```html
@forelse($users as $user)
    <li>{{ $user->name }}</li>
@empty
    <p>No users found in the register.</p>
@endforelse
```

### 2. `@pushOnce`
Ensures that a script, style, or meta tag is only pushed to a stack **once** per request, even if the component is used multiple times (e.g., in a loop).
```html
@pushOnce('scripts')
    <script src="https://cdn.example.com/chart.js"></script>
@endpushOnce
```

### 3. `@fragment`
Allows rendering only a specific part of a template file. This is essential for **HTMX** or partial AJAX updates.
```html
<!-- Inside user_profile.ml.php -->
<nav>...</nav>

@fragment('user-details')
    <div id="details">
        {{ $user->bio }}
    </div>
@endfragment

<footer>...</footer>
```

### 4. `@checked`, `@selected`, `@disabled` (Enhanced)
Automatic boolean attribute injection for cleaner forms.
```html
<input type="checkbox" @checked($user->is_active)>
<option value="1" @selected($user->role_id === 1)>Admin</option>
```

---

## 🛠️ Architectural Goals

### 1. Template Inheritance Refactor
Move from regex-based `@extends` to a more robust "Z-Template" layout engine that allows for block overrides and dynamic decoration.

### 2. Component Class Binding
Auto-detecting PHP classes for components (using PSR-4) to allow for complex logic outside the template file.
```php
namespace App\View\Components;

class Alert extends \MonkeysLegion\Template\Component {
    public function render() {
        return view('components.alert');
    }
}
```

### 3. Integrated Linter API
Expose the compile-time linting (PHP syntax check) as a public API so it can be integrated into IDE extensions (VSCode/JetBrains).

---