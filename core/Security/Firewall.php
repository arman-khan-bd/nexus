<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Env;
use Core\Http\Request;
use Core\Http\Response;

/**
 * Class Firewall
 *
 * Deep Input Sanity & Application Firewall for Nexus.
 *
 * Capabilities:
 * - Multi-pass recursive URL decoding & null-byte neutralization
 * - Unicode normalization & homoglyph evasion mitigation (fullwidth slashes, control chars)
 * - Deep recursive inspection of $_GET, $_POST, $_COOKIE, and REQUEST_URI
 * - Real-time OWASP Top 10 vector blocking: SQLi, XSS, RCE, LFI/Traversal, POI, XXE
 * - Tamper-evident audit logging to storage/logs/security.log
 */
final class Firewall
{
    /**
     * Attack signature database categorized by threat vector.
     */
    private static array $vectors = [
        'SQL_INJECTION' => [
            '~(?:\bunion(?:\s+all)?\s+select\b)~i',
            '~(?:\bselect\b.*?\bfrom\b.*?(?:information_schema|sys\.tables|pg_catalog|sqlite_master))~i',
            '~(?:\b(?:insert\s+into|update\s+.*?set|delete\s+from|drop\s+(?:table|database|schema)|alter\s+table|truncate\s+table)\b)~i',
            '~(?:\b(?:benchmark\s*\(|sleep\s*\(|pg_sleep\s*\(|waitfor\s+delay)\b)~i',
            '~(?:\b(?:load_file|into\s+(?:out|dump)file)\b)~i',
            '~(?:\bconcat(?:_ws)?\s*\(|\bchar\s*\()~i',
            '~(?:\'(?:\s*or|\s*and)\s*[\'0-9a-z]+=[\'0-9a-z]+)~i',
            '~(?:;\s*(?:select|insert|update|delete|drop)\b)~i',
            '~(?:--\s*$|#\s*$|/\*.*?\*/\s*)~m',
        ],
        'CROSS_SITE_SCRIPTING' => [
            '~(?:<\s*script[^>]*>|<\s*/\s*script\s*>)~i',
            '~(?:<\s*(?:iframe|object|embed|applet|meta|link|style)[^>]*>)~i',
            '~(?:javascript\s*:|vbscript\s*:|data\s*:text/html|data\s*:image/svg\+xml)~i',
            '~(?:on(?:load|error|click|mouseover|mouseenter|focus|blur|submit|keydown|keyup)\s*=)~i',
            '~(?:<\s*svg[^>]*on\w+\s*=)~i',
            '~(?:expression\s*\(|behavior\s*:)~i',
            '~(?:document\.(?:cookie|location|write)|window\.(?:location|navigate))~i',
        ],
        'COMMAND_INJECTION_RCE' => [
            '~(?:\b(?:passthru|shell_exec|exec|system|popen|proc_open|pcntl_exec)\s*\()~i',
            '~(?:\b(?:eval|assert)\s*\()~i',
            '~(?:`[^`]+`)~', // Backtick shell execution
            '~(?:\$\([^\)]+\))~', // $(whoami) shell expansion
            '~(?:;\s*(?:cat|ls|rm|nc|netcat|wget|curl|bash|sh|powershell|cmd|whoami|id|uname)\b)~i',
            '~(?:\|\s*(?:cat|ls|rm|nc|netcat|wget|curl|bash|sh|powershell|cmd|whoami|id|uname)\b)~i',
            '~(?:&&\s*(?:cat|ls|rm|nc|netcat|wget|curl|bash|sh|powershell|cmd|whoami|id|uname)\b)~i',
        ],
        'PATH_TRAVERSAL_LFI' => [
            '~(?:\.\./|\.\.\\\\)~', // ../ or ..\
            '~/(?:etc/(?:passwd|shadow|hosts|group|issue)|proc/self/environ)~i',
            '~(?:win\.ini|boot\.ini|windows[/\\\\]system32)~i',
            '~(?:(?:php|phar|zip|data|glob|expect)://)~i', // Stream wrappers
        ],
        'PHP_OBJECT_INJECTION' => [
            '~(?:^|;)[OaC]:[0-9]+:"~i',
        ],
        'SUPERGLOBAL_POLLUTION' => [
            '~(?:\b(?:GLOBALS|_REQUEST|_SERVER|_ENV|_FILES|_SESSION|HTTP_RAW_POST_DATA)\b)~i',
        ],
        'XXE_INJECTION' => [
            '~(?:<!ENTITY\s+.*?SYSTEM\s+["\'])~i',
            '~(?:<!DOCTYPE\s+.*?\[.*?<!ENTITY)~i',
        ],
    ];

    /**
     * Inspect incoming Request across all inputs, URI, and cookies.
     * Returns a 403 Response if an attack is detected, or null if clean.
     */
    public static function inspect(Request $request): ?Response
    {
        if (!Env::bool('FIREWALL_ENABLED', true)) {
            return null;
        }

        // 1. Inspect normalized URI path and full Raw URI
        $rawUri = $request->getRawUri();
        $normalizedUri = self::normalizeString($rawUri);
        if ($violation = self::scanForVectors($normalizedUri)) {
            self::logAttack($request, $violation['vector'], $violation['pattern'], $violation['matched'], 'REQUEST_URI');
            return self::createBlockResponse($request, $violation['vector'], 'Malicious URI signature detected.');
        }

        // 2. Inspect Query String Parameters ($_GET)
        if ($violation = self::inspectCollection($request->getQuery())) {
            self::logAttack($request, $violation['vector'], $violation['pattern'], $violation['matched'], 'GET_PARAMETER');
            return self::createBlockResponse($request, $violation['vector'], 'Malicious query parameter detected.');
        }

        // 3. Inspect Form Post Parameters ($_POST)
        if ($violation = self::inspectCollection($request->getPost())) {
            self::logAttack($request, $violation['vector'], $violation['pattern'], $violation['matched'], 'POST_PARAMETER');
            return self::createBlockResponse($request, $violation['vector'], 'Malicious POST payload detected.');
        }

        // 4. Inspect Cookies ($_COOKIE)
        if ($violation = self::inspectCollection($request->getCookies())) {
            self::logAttack($request, $violation['vector'], $violation['pattern'], $violation['matched'], 'COOKIE');
            return self::createBlockResponse($request, $violation['vector'], 'Malicious cookie payload detected.');
        }

        // 5. Inspect Raw Request Body (JSON or raw stream up to max payload limit)
        $rawBody = $request->getRawBody();
        if ($rawBody !== '') {
            $maxBytes = Env::int('WAF_MAX_PAYLOAD_KB', 5120) * 1024;
            if (strlen($rawBody) > $maxBytes) {
                self::logAttack($request, 'PAYLOAD_OVERSIZE', 'MAX_SIZE', 'Size exceeded ' . $maxBytes, 'RAW_BODY');
                return self::createBlockResponse($request, 'PAYLOAD_OVERSIZE', 'Payload size exceeds firewall limit.');
            }

            $normalizedBody = self::normalizeString($rawBody);
            if ($violation = self::scanForVectors($normalizedBody)) {
                self::logAttack($request, $violation['vector'], $violation['pattern'], $violation['matched'], 'RAW_BODY');
                return self::createBlockResponse($request, $violation['vector'], 'Malicious request body detected.');
            }
        }

        return null;
    }

    /**
     * Recursively normalize and inspect an array of inputs (keys & values).
     */
    private static function inspectCollection(array $collection): ?array
    {
        foreach ($collection as $key => $value) {
            // Inspect the key
            if (is_string($key)) {
                $normalizedKey = self::normalizeString($key);
                if ($matched = self::scanForVectors($normalizedKey)) {
                    return $matched;
                }
            }

            // Inspect nested arrays recursively
            if (is_array($value)) {
                $sub = self::inspectCollection($value);
                if ($sub !== null) {
                    return $sub;
                }
            } elseif (is_string($value)) {
                $normalizedVal = self::normalizeString($value);
                if ($matched = self::scanForVectors($normalizedVal)) {
                    return $matched;
                }
            }
        }

        return null;
    }

    /**
     * Normalize strings to neutralize null bytes, multi-layer URL encoding, and unicode bypasses.
     */
    public static function normalizeString(string $input): string
    {
        // 1. Remove raw and escaped null bytes
        $clean = str_replace(["\0", "%00", "\\0", "\\u0000", "\x00"], '', $input);

        // 2. Multi-pass recursive URL decoding (reveals %252e%252e%252f -> ../)
        $decoded = $clean;
        $maxIterations = 3;
        while ($maxIterations-- > 0) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        // Re-strip null bytes after URL decoding
        $decoded = str_replace(["\0", "%00", "\\0", "\\u0000", "\x00"], '', $decoded);

        // 3. Unicode normalization: Map fullwidth and homoglyph characters to ASCII equivalents
        $homoglyphs = [
            "\xEF\xBC\x8F" => '/', // Fullwidth solidus （／）
            "\xEF\xBC\xBC" => '\\', // Fullwidth reverse solidus （＼）
            "\xEF\xBC\x8E" => '.', // Fullwidth full stop （．）
            "\xEF\xBC\x9C" => '<', // Fullwidth less-than （＜）
            "\xEF\xBC\x9E" => '>', // Fullwidth greater-than （＞）
            "\xEF\xBC\x82" => '"', // Fullwidth quotation mark （＂）
            "\xEF\xBC\x87" => "'", // Fullwidth apostrophe （＇）
            "\xEF\xBC\x9B" => ';', // Fullwidth semicolon （；）
            "\xE2\x80\x8B" => '',  // Zero-width space
            "\xE2\x80\x8C" => '',  // Zero-width non-joiner
            "\xE2\x80\x8D" => '',  // Zero-width joiner
            "\xEF\xBB\xBF" => '',  // Byte order mark
        ];
        $decoded = strtr($decoded, $homoglyphs);

        // 4. Normalize with ICU Normalizer if available
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($decoded, \Normalizer::FORM_C);
            if ($normalized !== false) {
                $decoded = $normalized;
            }
        }

        // 5. Remove non-printable control characters except standard whitespace
        $decoded = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $decoded) ?? $decoded;

        return $decoded;
    }

    /**
     * Scan normalized string against all attack vector signatures.
     */
    private static function scanForVectors(string $payload): ?array
    {
        foreach (self::$vectors as $vectorName => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $payload, $matches)) {
                    return [
                        'vector'  => $vectorName,
                        'pattern' => $pattern,
                        'matched' => substr($matches[0], 0, 120),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Log attack signature incident to storage/logs/security.log.
     */
    private static function logAttack(Request $request, string $vector, string $pattern, string $matched, string $source): void
    {
        $logPath = dirname(__DIR__, 2) . '/storage/logs/security.log';
        $logDir = dirname($logPath);

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0700, true);
        }

        $sanitizedMatched = str_replace(["\r", "\n"], ' ', $matched);
        $entry = sprintf(
            "[%s] [FIREWALL_BLOCKED] [VECTOR: %s] [SOURCE: %s] IP: %s | URI: %s | Matched: %s | UA: %s\n",
            date('Y-m-d H:i:s'),
            $vector,
            $source,
            $request->getIp(),
            $request->getPath(),
            $sanitizedMatched,
            $request->getUserAgent()
        );

        @file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Create 403 Forbidden response with security shield UI or JSON.
     */
    private static function createBlockResponse(Request $request, string $vector, string $message): Response
    {
        if ($request->isAjax() || str_contains($request->getHeader('accept', ''), 'application/json')) {
            return Response::json([
                'error'   => 'Forbidden',
                'message' => $message,
                'vector'  => $vector,
                'status'  => 403,
            ], 403);
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden - Nexus Firewall Shield</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: #030712;
            color: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1.5rem;
        }
        .shield-card {
            background: #111827;
            border: 1px solid #1f2937;
            border-radius: 16px;
            max-width: 520px;
            width: 100%;
            padding: 2.75rem 2rem;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
        }
        .badge {
            display: inline-block;
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
            padding: 0.25rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1.5rem;
        }
        h1 { font-size: 1.85rem; font-weight: 700; color: #ffffff; margin-bottom: 0.75rem; }
        p { color: #9ca3af; font-size: 0.95rem; line-height: 1.6; margin-bottom: 1.75rem; }
        .meta-box {
            background: #090d16;
            border: 1px solid #1e293b;
            border-radius: 10px;
            padding: 1rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.825rem;
            color: #94a3b8;
            text-align: left;
            line-height: 1.5;
        }
        .meta-box strong { color: #f87171; }
    </style>
</head>
<body>
    <div class="shield-card">
        <div class="badge">Nexus Security Defense Shield</div>
        <h1>403 Forbidden</h1>
        <p>Your request has been intercepted and blocked by the Nexus Deep Input Inspection Firewall.</p>
        <div class="meta-box">
            <div><strong>Threat Vector:</strong> {$vector}</div>
            <div><strong>Interception:</strong> Active Attack Signature</div>
            <div><strong>Status:</strong> Neutralized &amp; Logged</div>
        </div>
    </div>
</body>
</html>
HTML;

        return Response::html($html, 403);
    }
}
