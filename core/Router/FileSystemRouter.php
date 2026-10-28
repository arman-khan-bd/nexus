<?php

declare(strict_types=1);

namespace Core\Router;

/**
 * Class FileSystemRouter
 *
 * Backward-compatible extension of AppRouter.
 * Implements Next.js App Router conventions: dynamic [id] segments,
 * nested layouts, zero-AJAX SPA morphing, and server actions.
 */
class FileSystemRouter extends AppRouter
{
    // Inherits all AppRouter functionality
}
