<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use MonkeysLegion\Template\Attributes\ViewComponent;
use MonkeysLegion\Template\Contracts\ComponentInterface;

/**
 * Resolves `<x-name>` tags to component instances or anonymous templates.
 *
 * Resolution order:
 * 1. Registered class-based components (via #[ViewComponent] or manual registration)
 * 2. Registered function components (closures/static methods)
 * 3. Namespace-based class lookup (App\View\Components\*)
 * 4. Anonymous template file
 */
final class ComponentResolver
{
    /** @var array<string, class-string<ComponentInterface>> Registered class components */
    private array $classComponents = [];

    /** @var array<string, callable> Registered function components */
    private array $functionComponents = [];

    /** @var array<string, string> Cache: component name → resolution type */
    private array $resolutionCache = [];

    /** @var list<string> Namespace prefixes to search for class components */
    private array $namespaces = ['App\\View\\Components\\'];

    /** @var list<string> Paths to search for anonymous templates */
    private array $componentPaths = [];

    /**
     * Register a class-based component.
     *
     * @param class-string<ComponentInterface> $className
     */
    public function registerClass(string $name, string $className): void
    {
        $this->classComponents[$name] = $className;
        unset($this->resolutionCache[$name]);
    }

    /**
     * Register a function component (closure or callable).
     */
    public function registerFunction(string $name, callable $handler): void
    {
        $this->functionComponents[$name] = $handler;
        unset($this->resolutionCache[$name]);
    }

    /**
     * Auto-discover class components from a given class using #[ViewComponent].
     *
     * @param class-string $className
     */
    public function discoverFromClass(string $className): void
    {
        $reflection = new \ReflectionClass($className);
        $attrs = $reflection->getAttributes(ViewComponent::class);

        foreach ($attrs as $attr) {
            $viewComponent = $attr->newInstance();
            /** @var class-string<ComponentInterface> $className */
            $this->registerClass($viewComponent->name, $className);
        }
    }

    /**
     * Add a namespace prefix for class component lookup.
     */
    public function addNamespace(string $namespace): void
    {
        $this->namespaces[] = rtrim($namespace, '\\') . '\\';
    }

    /**
     * Add a path for anonymous component template lookup.
     */
    public function addComponentPath(string $path): void
    {
        $this->componentPaths[] = rtrim($path, '/');
    }

    /**
     * Set component paths (replaces existing).
     *
     * @param list<string> $paths
     */
    public function setComponentPaths(array $paths): void
    {
        $this->componentPaths = $paths;
    }

    /**
     * Resolve a component name to a ComponentInterface instance, callable, or null.
     *
     * @param array<string, mixed> $attributes
     * @return array{type: string, value: ComponentInterface|callable|string|null}
     */
    public function resolve(string $name, array $attributes = []): array
    {
        // 1. Registered class components
        if (isset($this->classComponents[$name])) {
            $className = $this->classComponents[$name];
            $instance = $this->instantiateClass($className, $attributes);
            return ['type' => 'class', 'value' => $instance];
        }

        // 2. Registered function components
        if (isset($this->functionComponents[$name])) {
            return ['type' => 'function', 'value' => $this->functionComponents[$name]];
        }

        // 3. Namespace-based class lookup
        $studlyName = $this->toStudlyCase($name);
        foreach ($this->namespaces as $ns) {
            $fullClass = $ns . $studlyName;
            if (class_exists($fullClass)) {
                /** @var class-string<ComponentInterface> $fullClass */
                $this->classComponents[$name] = $fullClass;
                $instance = $this->instantiateClass($fullClass, $attributes);
                return ['type' => 'class', 'value' => $instance];
            }
        }

        // 4. Anonymous template file
        $templatePath = $this->findAnonymousTemplate($name);
        if ($templatePath !== null) {
            return ['type' => 'anonymous', 'value' => $templatePath];
        }

        return ['type' => 'none', 'value' => null];
    }

    /**
     * Check if a component name can be resolved.
     */
    public function has(string $name): bool
    {
        if (isset($this->classComponents[$name]) || isset($this->functionComponents[$name])) {
            return true;
        }

        // Check namespace lookup
        $studlyName = $this->toStudlyCase($name);
        foreach ($this->namespaces as $ns) {
            if (class_exists($ns . $studlyName)) {
                return true;
            }
        }

        return $this->findAnonymousTemplate($name) !== null;
    }

    /**
     * Get all registered class component names.
     *
     * @return list<string>
     */
    public function getRegisteredClassNames(): array
    {
        return array_keys($this->classComponents);
    }

    /**
     * Get all registered function component names.
     *
     * @return list<string>
     */
    public function getRegisteredFunctionNames(): array
    {
        return array_keys($this->functionComponents);
    }

    /**
     * Instantiate a class component with attributes.
     *
     * @param class-string<ComponentInterface> $className
     * @param array<string, mixed> $attributes
     */
    private function instantiateClass(string $className, array $attributes): ComponentInterface
    {
        $reflection = new \ReflectionClass($className);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            $instance = $reflection->newInstance();
        } else {
            $args = $this->resolveConstructorArgs($constructor, $attributes);
            $instance = $reflection->newInstanceArgs($args);
        }

        /** @var ComponentInterface $instance */
        return $instance->withAttributes($attributes);
    }

    /**
     * Resolve constructor arguments from attributes.
     *
     * @param array<string, mixed> $attributes
     * @return list<mixed>
     */
    private function resolveConstructorArgs(\ReflectionMethod $constructor, array $attributes): array
    {
        $args = [];

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (isset($attributes[$name])) {
                $args[] = $attributes[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = null;
            }
        }

        return $args;
    }

    /**
     * Find an anonymous component template by name.
     */
    private function findAnonymousTemplate(string $name): ?string
    {
        // Convert dot notation to path: user.profile -> user/profile
        $path = str_replace('.', '/', $name);

        foreach ($this->componentPaths as $basePath) {
            $candidates = [
                "{$basePath}/{$path}.ml.php",
                "{$basePath}/{$path}/index.ml.php",
            ];

            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Convert kebab-case or dot.notation to StudlyCase.
     */
    private function toStudlyCase(string $name): string
    {
        // Convert dots and hyphens to spaces, ucwords, remove spaces
        return str_replace(' ', '', ucwords(str_replace(['-', '.', '_'], ' ', $name)));
    }
}
