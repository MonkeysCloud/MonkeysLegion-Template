<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Support;

use RuntimeException;

/**
 * Context-aware escaping helper.
 */
class Escaper
{
    /**
     * Escape HTML content.
     */
    public static function html(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape HTML attribute value.
     */
    public static function attr(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Escape JavaScript string/data.
     */
    public static function js(mixed $value): string
    {
        $encoded = json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        return $encoded !== false ? $encoded : '""';
    }

    /**
     * Escape URL.
     */
    public static function url(mixed $value): string
    {
        return rawurlencode((string) ($value ?? ''));
    }

    /**
     * Escape CSS content.
     *
     * Properly escapes values for use inside CSS property values or identifiers,
     * preventing CSS injection attacks (e.g., expression(), url(), closing braces).
     * Uses CSS Unicode escape sequences (\XXXXXX) for all non-safe characters,
     * correctly handling multi-byte UTF-8 input via mb_ord().
     */
    public static function css(mixed $value): string
    {
        $value = (string) ($value ?? '');

        // Escape characters that can break out of CSS contexts or inject malicious CSS.
        // Replace any character that is not alphanumeric, hyphen, underscore, or space
        // with its Unicode escape equivalent (for CSS identifier safety).
        return (string) preg_replace_callback(
            '/[^\w\s\-]/u',
            static fn(array $m): string => '\\' . str_pad(strtoupper(dechex(mb_ord($m[0], 'UTF-8'))), 6, '0', STR_PAD_LEFT) . ' ',
            $value,
        );
    }

    /**
     * Allow raw content (no-op), but useful for explicit intent.
     */
    public static function raw(mixed $value): string
    {
        return (string) ($value ?? '');
    }

    /**
     * General escape method based on type.
     */
    public static function escape(string $type, mixed $value): string
    {
        return match ($type) {
            'html' => self::html($value),
            'attr' => self::attr($value),
            'js'   => self::js($value),
            'url'  => self::url($value),
            'css'  => self::css($value),
            'raw'  => self::raw($value),
            default => throw new RuntimeException("Unknown escape context: {$type}"),
        };
    }

    /**
     * Check if strict mode allows this raw echo.
     * To be called from compiled code when strict mode is active.
     */
    public static function checkStrictRaw(mixed $value): string
    {
        // If we are here, strict mode is ON and {!! !!} was used without explicit @escape('raw', ...)
        // We trigger a warning.
        trigger_error(
            "Security Warning: Unescaped output detected in Strict Mode: " . htmlspecialchars(substr((string)$value, 0, 50)) . "... Use @escape('raw', ...) if this is intended.",
            E_USER_WARNING
        );
        return (string) ($value ?? '');
    }
}
