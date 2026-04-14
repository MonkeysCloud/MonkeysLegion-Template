<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Support;

/**
 * Processes `<style scoped>` blocks in component templates.
 *
 * Inspired by Svelte/Vue SFCs — generates unique scope IDs per component
 * and rewrites CSS selectors to include the scope attribute, preventing
 * style leakage between components.
 */
final class ScopedStyleCompiler
{
    /** @var array<string, string> Collected scoped styles keyed by scope ID */
    private array $styles = [];

    /**
     * Process a component template source, extracting and scoping `<style scoped>`.
     *
     * @return array{html: string, scopeId: string|null}
     */
    public function process(string $source, string $componentPath): array
    {
        $scopeId = $this->generateScopeId($componentPath);

        // Extract <style scoped>...</style> blocks
        $html = (string) preg_replace_callback(
            '/<style\s+scoped\s*>(.*?)<\/style>/si',
            function (array $m) use ($scopeId): string {
                $css = $m[1];
                $scopedCss = $this->scopeSelectors($css, $scopeId);
                $this->styles[$scopeId] = ($this->styles[$scopeId] ?? '') . $scopedCss;
                return ''; // Remove the style block from HTML
            },
            $source,
        );

        // Add scope attribute to root elements in the template
        $html = $this->addScopeToRootElements($html, $scopeId);

        return [
            'html' => $html,
            'scopeId' => !empty($this->styles[$scopeId]) ? $scopeId : null,
        ];
    }

    /**
     * Get all collected scoped styles as a combined CSS string.
     */
    public function getCollectedStyles(): string
    {
        return implode("\n", $this->styles);
    }

    /**
     * Get scoped styles for a specific scope ID.
     */
    public function getStylesForScope(string $scopeId): string
    {
        return $this->styles[$scopeId] ?? '';
    }

    /**
     * Check if there are any collected scoped styles.
     */
    public function hasStyles(): bool
    {
        return !empty($this->styles);
    }

    /**
     * Clear all collected styles.
     */
    public function clear(): void
    {
        $this->styles = [];
    }

    /**
     * Generate a unique scope ID from the component path.
     */
    public function generateScopeId(string $path): string
    {
        return 'ml-' . substr(md5($path), 0, 6);
    }

    /**
     * Rewrite CSS selectors to include the scope attribute.
     *
     * `.card` → `.card[data-ml-a3f2c1]`
     * `.card h3` → `.card[data-ml-a3f2c1] h3`
     */
    public function scopeSelectors(string $css, string $scopeId): string
    {
        // Match CSS rules: selector { ... }
        return (string) preg_replace_callback(
            '/([^{]+)\{([^}]*)\}/s',
            function (array $m) use ($scopeId): string {
                $selectors = $m[1];
                $body = $m[2];

                // Process each comma-separated selector
                $scopedSelectors = array_map(
                    fn(string $sel) => $this->scopeSingleSelector(trim($sel), $scopeId),
                    explode(',', $selectors),
                );

                return implode(', ', $scopedSelectors) . " {{$body}}";
            },
            $css,
        );
    }

    /**
     * Scope a single CSS selector.
     *
     * Adds `[data-{scopeId}]` after the first simple selector.
     */
    private function scopeSingleSelector(string $selector, string $scopeId): string
    {
        if (empty(trim($selector))) {
            return $selector;
        }

        // Handle pseudo-selectors and combinators
        // Add scope attribute after the first non-pseudo part
        $parts = preg_split('/(\s+)/', $selector, 2, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || empty($parts)) {
            return $selector;
        }

        $first = $parts[0];
        $rest = implode('', array_slice($parts, 1));

        return "{$first}[data-{$scopeId}]{$rest}";
    }

    /**
     * Add `data-{scopeId}` attribute to root-level HTML elements.
     */
    private function addScopeToRootElements(string $html, string $scopeId): string
    {
        // Add scope attribute to the first opening tag found
        // This is a simplified approach — adds to ALL top-level tags
        return (string) preg_replace(
            '/(<[a-zA-Z][a-zA-Z0-9]*)([\s>])/',
            "$1 data-{$scopeId}$2",
            $html,
            1, // Only first match (root element)
        );
    }
}
