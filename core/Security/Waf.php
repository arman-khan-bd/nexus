<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Http\Request;
use Core\Http\Response;

/**
 * Class Waf
 *
 * Backward-compatible facade delegating directly to the primary
 * deep inspection engine in Core\Security\Firewall.
 */
final class Waf
{
    /**
     * Inspect request for malicious payloads.
     */
    public static function inspect(Request $request): ?Response
    {
        return Firewall::inspect($request);
    }
}
