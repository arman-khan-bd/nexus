<?php

declare(strict_types=1);

namespace Core\Security;

use JsonException;

/**
 * Class Escaper
 *
 * Context-Aware XSS Defense & Output Sanitizer for Nexus.
 *
 * Provides context-specific escaping engines:
 * - HTML body context (htmlspecialchars with ENT_QUOTES | ENT_SUBSTITUTE)
 * - HTML attribute context
 * - JavaScript variable & attribute contexts (JSON_HEX_* encoding)
 * - URL context (blocks javascript:, data:, vbscript: protocol vectors)
 * - CSS context
 */
final class Escaper
{
    /**
     * Allowed URI schemes in href / src attributes.
     */
    private const ALLOWED_URL_SCHEMES = [
        'http',
        'https',
        'mailto',
        'tel',
    ];

    /**
     * Escape for standard HTML body context.
     */
    public static function html(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value) || is_object($value)) {
            return self::html(json_encode($value, JSON_UNESCAPED_UNICODE));
        }

        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape for HTML element attributes (e.g. title="...", value="...", data-*="...").
     */
    public static function attr(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $str = is_scalar($value) ? (string)$value : (string)json_encode($value);
        return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape for JavaScript contexts, script tags, and inline event handlers (Alpine.js x-data, @click).
     * Prevents XSS breakout by escaping <, >, &, ', and " into unicode/hex escapes.
     */
    public static function js(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return '""';
        }
    }

    /**
     * Validate and escape URL contexts (href="...", src="...").
     * Blocks malicious URI protocols like javascript:, vbscript:, data:text/html.
     *
     * @param mixed $value URL string
     * @param string $fallback Fallback safe link if disallowed scheme is detected
     * @return string Validated and escaped URL
     */
    public static function url(mixed $value, string $fallback = '#'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $url = trim((string)$value);

        // Neutralize null bytes and control whitespace that could hide protocol
        $sanitized = str_replace(["\0", "%00", "\r", "\n", "\t"], '', $url);

        // Allow relative URLs, query strings, and fragment anchors
        if (str_starts_with($sanitized, '/') ||
            str_starts_with($sanitized, './') ||
            str_starts_with($sanitized, '../') ||
            str_starts_with($sanitized, '?') ||
            str_starts_with($sanitized, '#')) {
            return htmlspecialchars($sanitized, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        // Parse scheme from absolute URL
        $colonPos = strpos($sanitized, ':');
        if ($colonPos !== false) {
            $scheme = strtolower(substr($sanitized, 0, $colonPos));

            // Strip non-alpha characters from scheme
            $scheme = preg_replace('/[^a-z0-9\+\-\.]/', '', $scheme);

            if (!in_array($scheme, self::ALLOWED_URL_SCHEMES, true)) {
                // Malicious protocol (e.g. javascript:alert(1), data:text/html)
                return htmlspecialchars($fallback, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        return htmlspecialchars($sanitized, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Sanitize CSS property context.
     */
    public static function css(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $css = (string)$value;
        // Strip expressions, behavior, script, and url wrappers
        $css = preg_replace('/(?:expression|behavior|javascript|vbscript|url)\s*\(.*?\)/i', '', $css) ?? '';
        return htmlspecialchars($css, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Return raw unescaped value for explicit raw directives ({!! $val !!}).
     */
    public static function raw(mixed $value): string
    {
        return (string)$value;
    }
}
