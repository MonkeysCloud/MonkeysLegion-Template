<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

/**
 * Manages strict variable scoping for templates
 * Variables are only available in their declaring scope
 * and must be explicitly passed to components
 */
class VariableScope
{
    /** @var array Stack of variable scopes, with the current scope at the end */
    private array $scopeStack = [];

    /** @var VariableScope|null The current instance for the request */
    private static ?VariableScope $instance = null;

    /** @var array Global data accessible to root templates only */
    private array $globalData = [];

    /**
     * Create a new VariableScope instance
     * 
     * @param array $initialData Initial variables for root scope
     */
    public function __construct(array $initialData = [])
    {
        // Initialize with a root scope
        $this->scopeStack[] = $initialData;
        $this->globalData = $initialData;
    }

    /**
     * Get the current VariableScope instance
     * 
     * @return VariableScope
     */
    public static function getCurrent(): VariableScope
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Set the current VariableScope instance
     * 
     * @param VariableScope $scope
     * @return void
     */
    public static function setCurrent(VariableScope $scope): void
    {
        self::$instance = $scope;
    }

    /**
     * Create a new completely isolated scope with only explicitly passed variables
     * 
     * @param array $passedVars Variables explicitly passed to this scope
     * @param array $declaredParams Parameters declared with default values
     * @return void
     */
    public function createIsolatedScope(array $passedVars = [], array $declaredParams = []): void
    {
        // Merge with passed vars taking precedence over defaults
        // This ensures that any explicitly passed value (even null or empty string) overrides the default
        $newScope = array_merge($declaredParams, $passedVars);

        // Log the final scope for debugging
        $paramsJson = json_encode($declaredParams);
        $varsJson = json_encode($passedVars);
        $scopeJson = json_encode($newScope);
        error_log("Creating isolated scope - Params: {$paramsJson}, Vars: {$varsJson}, Final: {$scopeJson}");

        // No variables are automatically inherited from parent scope
        $this->scopeStack[] = $newScope;
    }

    /**
     * Create a new scope that inherits specified variables from parent
     * Used for includes which may need some parent context
     * 
     * @param array $additionalVars Additional variables for this scope
     * @return void
     */
    public function pushScope(array $additionalVars = []): void
    {
        // For includes, we merge with the parent scope
        $parentScope = $this->getCurrentScope();
        $newScope = array_merge($parentScope, $additionalVars);

        $this->scopeStack[] = $newScope;
    }

    /**
     * Remove the current scope and return to parent scope
     * 
     * @return void
     */
    public function popScope(): void
    {
        if (count($this->scopeStack) > 1) {
            array_pop($this->scopeStack);
        }
    }

    /**
     * Set global data accessible to root templates
     * 
     * @param array $data Global data
     * @return void
     */
    public function setGlobalData(array $data): void
    {
        $this->globalData = $data;

        // Update root scope with global data
        if (!empty($this->scopeStack)) {
            $this->scopeStack[0] = array_merge($this->scopeStack[0], $data);
        }
    }

    /**
     * Get the current scope's variables
     * 
     * @return array
     */
    public function getCurrentScope(): array
    {
        return end($this->scopeStack) ?: [];
    }

    /**
     * Get a variable from the current scope
     * 
     * @param string $name Variable name
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function get(string $name, mixed $default = null): mixed
    {
        $currentScope = $this->getCurrentScope();
        return $currentScope[$name] ?? $default;
    }

    /**
     * Set a variable in the current scope
     * 
     * @param string $name Variable name
     * @param mixed $value Value to set
     * @return void
     */
    public function set(string $name, mixed $value): void
    {
        $currentScope = &$this->scopeStack[count($this->scopeStack) - 1];
        $currentScope[$name] = $value;
    }

    /**
     * Check if a variable exists in the current scope
     * 
     * @param string $name Variable name
     * @return bool
     */
    public function has(string $name): bool
    {
        $currentScope = $this->getCurrentScope();
        return array_key_exists($name, $currentScope);
    }

    /**
     * Reset the scope stack to initial state
     * Useful for testing or starting fresh
     * 
     * @return void
     */
    public function reset(): void
    {
        $this->scopeStack = [$this->globalData];
    }

    /**
     * Get the depth of the current scope stack
     * Useful for debugging
     * 
     * @return int
     */
    public function getDepth(): int
    {
        return count($this->scopeStack);
    }
}
