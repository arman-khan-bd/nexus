<?php

declare(strict_types=1);

/**
 * Nexus Framework - Step 6 Automated Test Suite
 * App Router, Zero-AJAX SPA Engine, Server Actions & CLI Toolchain
 */

$baseDir = dirname(__DIR__);

// Load Autoloader
if (file_exists($baseDir . '/vendor/autoload.php')) {
    require_once $baseDir . '/vendor/autoload.php';
} else {
    spl_autoload_register(function (string $class) use ($baseDir) {
        $prefixMap = [
            'Core\\'       => $baseDir . '/core/',
            'App\\'        => $baseDir . '/app/',
            'Components\\' => $baseDir . '/components/',
        ];
        foreach ($prefixMap as $prefix => $base) {
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) === 0) {
                $relative = substr($class, $len);
                $file = $base . str_replace('\\', '/', $relative) . '.php';
                if (file_exists($file)) {
                    require_once $file;
                    return;
                }
            }
        }
    });
}

// Bootstrap Environment
\Core\Env::load($baseDir . '/.env');

use Core\Http\Request;
use Core\Http\Response;
use Core\Router\AppRouter;
use Core\Router\FileSystemRouter;
use Core\Router\ServerAction;
use Core\Security\Csrf;
use Core\Security\SessionGuard;
use Core\Console\Cli;

echo "\n\033[1;34m========================================================\033[0m\n";
echo "\033[1;36mNEXUS APP ROUTER, SPA & SERVER ACTIONS - STEP 6 SUITE   \033[0m\n";
echo "\033[1;34m========================================================\033[0m\n";

$testsPassed = 0;

// Setup test routes directory
$testPagesDir = $baseDir . '/storage/test_pages_' . bin2hex(random_bytes(4));
mkdir($testPagesDir, 0755, true);

// 1. Root layout
file_put_contents($testPagesDir . '/layout.php', '<!DOCTYPE html><html><head><title>Root Layout</title></head><body><header>Nav</header><main id="nexus-root"><?= $children ?></main><footer>Foot</footer></body></html>');

// 2. Root page
file_put_contents($testPagesDir . '/page.php', '<div class="home-page"><h1>Home Page</h1></div>');

// 3. Dynamic parameter route: /posts/[id]/page.php with sub-layout
mkdir($testPagesDir . '/posts/[id]', 0755, true);
file_put_contents($testPagesDir . '/posts/layout.php', '<section class="posts-sublayout"><?= $children ?></section>');
file_put_contents($testPagesDir . '/posts/[id]/page.php', '<article class="post-item"><h2>Post ID: <?= $params[\'id\'] ?></h2></article>');

// 4. Catch-all route: /docs/[...slug]/page.php
mkdir($testPagesDir . '/docs/[...slug]', 0755, true);
file_put_contents($testPagesDir . '/docs/[...slug]/page.php', '<div class="docs">Slug: <?= $params[\'slug\'] ?></div>');

// -----------------------------------------------------------------------------
// Test 1: AppRouter File-System Routing & Hierarchical Nested Layouts
// -----------------------------------------------------------------------------
echo "[1/4] Testing Next.js App Router convention & layout nesting...\n";

$router = new AppRouter($testPagesDir);

// 1A. Root route
$reqHome = new Request('GET', '/', '/', [], [], [], [], [], []);
$resHome = $router->dispatch($reqHome);
assert($resHome->getStatus() === 200, "Home route should return 200");
assert(str_contains($resHome->getBody(), '<!DOCTYPE html>'), "Home route should contain root doctype");
assert(str_contains($resHome->getBody(), '<div class="home-page"><h1>Home Page</h1></div>'), "Home route should contain page content");
assert(str_contains($resHome->getBody(), '<header>Nav</header>'), "Home route should include root layout header");

// 1B. Dynamic route: /posts/99
$reqPost = new Request('GET', '/posts/99', '/posts/99', [], [], [], [], [], []);
$resPost = $router->dispatch($reqPost);
assert($resPost->getStatus() === 200, "Dynamic route should return 200");
assert(str_contains($resPost->getBody(), 'Post ID: 99'), "Dynamic route parameter [id] should be bound to 99");
assert(str_contains($resPost->getBody(), 'class="posts-sublayout"'), "Post route should nest posts sub-layout");
assert(str_contains($resPost->getBody(), '<main id="nexus-root">'), "Post route should nest inside root layout");

// 1C. Catch-all route: /docs/api/v1/security
$reqDocs = new Request('GET', '/docs/api/v1/security', '/docs/api/v1/security', [], [], [], [], [], []);
$resDocs = $router->dispatch($reqDocs);
assert($resDocs->getStatus() === 200, "Catch-all route should return 200");
assert(str_contains($resDocs->getBody(), 'Slug: api/v1/security'), "Catch-all [...slug] should capture full remaining path");

// 1D. 404 Not Found
$req404 = new Request('GET', '/non-existent-page', '/non-existent-page', [], [], [], [], [], []);
$res404 = $router->dispatch($req404);
assert($res404->getStatus() === 404, "Unmatched route should return 404");

// 1E. Backward compatibility: FileSystemRouter extends AppRouter
$fsRouter = new FileSystemRouter($testPagesDir);
$resFs = $fsRouter->dispatch($reqPost);
assert($resFs->getStatus() === 200, "FileSystemRouter should match route identically");

echo "  \033[1;32m-> PASS:\033[0m Static routes, dynamic [id] parameters, [...slug] catch-all, and multi-tier layout nesting verified.\n";
$testsPassed++;

// -----------------------------------------------------------------------------
// Test 2: Zero-AJAX SPA Bridge & Alpine Morph Partial Rendering
// -----------------------------------------------------------------------------
echo "[2/4] Testing X-NextPHP-SPA headers & surgical DOM morph response...\n";

// 2A. Request with X-NextPHP-SPA: true
$headersSpa = [
    'x-nextphp-spa'    => 'true',
    'x-requested-with' => 'xmlhttprequest',
];
$reqSpa = new Request('GET', '/posts/42', '/posts/42', [], [], $headersSpa, [], [], []);
assert($reqSpa->isSpa() === true, "Request::isSpa() should detect X-NextPHP-SPA");

$resSpa = $router->dispatch($reqSpa);
assert($resSpa->getStatus() === 200, "SPA request should return 200");
assert($resSpa->getHeader('x-nextphp-spa') === 'true', "SPA response must have X-NextPHP-SPA header");
assert($resSpa->getHeader('x-nextphp-morph') === 'true', "SPA response must have X-NextPHP-Morph header");

// SPA partial must NOT contain full doctype or redundant root header
$bodySpa = $resSpa->getBody();
assert(!str_contains($bodySpa, '<!DOCTYPE html>'), "SPA partial should NOT contain redundant outer html doctype");
assert(!str_contains($bodySpa, '<header>Nav</header>'), "SPA partial should NOT re-render persistent root nav header");
assert(str_contains($bodySpa, 'id="nexus-root"'), "SPA partial must be wrapped in #nexus-root for Alpine Morph");
assert(str_contains($bodySpa, 'Post ID: 42'), "SPA partial must contain inner target post content");
assert(str_contains($bodySpa, 'class="posts-sublayout"'), "SPA partial must preserve intermediate sub-layouts");

// 2B. Client JS Bridge assets/js/nextphp-spa.js check
$spaScriptPath = $baseDir . '/assets/js/nextphp-spa.js';
assert(file_exists($spaScriptPath), "assets/js/nextphp-spa.js must exist");
$spaScriptContent = (string)file_get_contents($spaScriptPath);
assert(str_contains($spaScriptContent, 'runServerAction'), "nextphp-spa.js must export runServerAction");
assert(str_contains($spaScriptContent, 'prefetch'), "nextphp-spa.js must support route prefetching on hover");
assert(str_contains($spaScriptContent, 'NexusToast'), "nextphp-spa.js must include Shadcn toast notifications");
assert(str_contains($spaScriptContent, 'Alpine.morph'), "nextphp-spa.js must morph via Alpine.morph");

echo "  \033[1;32m-> PASS:\033[0m X-NextPHP-SPA partial DOM morphing verified & nextphp-spa.js client bridge confirmed.\n";
$testsPassed++;

// -----------------------------------------------------------------------------
// Test 3: Server Actions Engine & Security Defense
// -----------------------------------------------------------------------------
echo "[3/4] Testing Server Actions execution, CSRF defense & UI state payloads...\n";

ServerAction::clear();
$validCsrf = Csrf::token();

// 3A. Register global server action
ServerAction::register('updateProfile', function (array $input, Request $req) {
    return [
        'success'  => true,
        'user'     => $input['name'],
        'toast'    => ['type' => 'success', 'message' => "Profile updated for {$input['name']}!"],
        'redirect' => '/dashboard'
    ];
});

// 3B. Execute Action with Valid CSRF
$actionHeaders = [
    'x-nextphp-action' => 'updateProfile',
    'x-csrf-token'     => $validCsrf,
    'content-type'     => 'application/json',
];
$jsonPayload = [
    'name'   => " Alice Developer \0 ", // contains null-byte and whitespace to verify sanitization
    'role'   => 'Lead Architect',
    '_token' => $validCsrf,
];
$rawJson = json_encode($jsonPayload);

$reqAction = new Request('POST', '/posts/1', '/posts/1', [], [], $actionHeaders, [], [], [], $rawJson);
assert(ServerAction::isAction($reqAction) === true, "Should detect incoming Server Action");
assert(ServerAction::getActionName($reqAction) === 'updateProfile', "Should resolve action name 'updateProfile'");

$resAction = ServerAction::handle($reqAction);
assert($resAction instanceof Response, "Action execution must return a Response");
assert($resAction->getStatus() === 200, "Action response status should be 200");

$actionData = json_decode($resAction->getBody(), true);
assert($actionData['success'] === true, "Action response success flag must be true");
assert($actionData['user'] === 'Alice Developer', "Input sanitization must strip null-bytes and trim whitespace");
assert($actionData['toast']['type'] === 'success', "Action response should format toast notifications");
assert($actionData['redirect'] === '/dashboard', "Action response should support server redirects");

// 3C. CSRF Protection Check: Reject Mutating Server Action with Invalid CSRF
$badCsrfHeaders = [
    'x-nextphp-action' => 'updateProfile',
    'x-csrf-token'     => 'forged_or_expired_token_12345',
    'content-type'     => 'application/json',
];
$reqBadCsrf = new Request('POST', '/posts/1', '/posts/1', [], [], $badCsrfHeaders, [], [], [], $rawJson);
$resBadCsrf = ServerAction::handle($reqBadCsrf);
assert($resBadCsrf->getStatus() === 403, "Action with invalid CSRF token must be blocked with 403 Forbidden");

// 3D. DOM Morph Server Action: Handler returns HTML string
ServerAction::register('reloadCard', function (array $input) {
    return '<div id="card-content" class="p-4 bg-blue-950">Updated Card Dynamic State</div>';
});
$morphHeaders = [
    'x-nextphp-action' => 'reloadCard',
    'x-csrf-token'     => $validCsrf,
];
$reqMorph = new Request('POST', '/posts/1', '/posts/1', [], [], $morphHeaders, [], [], []);
$resMorph = ServerAction::handle($reqMorph);
assert($resMorph->getHeader('x-nextphp-morph') === 'true', "String result must return X-NextPHP-Morph header");
assert(str_contains($resMorph->getBody(), 'Updated Card Dynamic State'), "String result must return HTML for morphing");

// 3E. Exception Containment: Action throws Throwable
ServerAction::register('failingAction', function () {
    throw new RuntimeException("Database timeout in action handler");
});
$failHeaders = [
    'x-nextphp-action' => 'failingAction',
    'x-csrf-token'     => $validCsrf,
];
$reqFail = new Request('POST', '/posts/1', '/posts/1', [], [], $failHeaders, [], [], []);
$resFail = ServerAction::handle($reqFail);
assert($resFail->getStatus() === 500, "Action throwing exception must return 500 JSON without fatal crash");
$failData = json_decode($resFail->getBody(), true);
assert($failData['success'] === false, "Failing action must report success=false");
assert(str_contains($failData['message'], 'Database timeout'), "Error message should be contained in JSON");

echo "  \033[1;32m-> PASS:\033[0m Server actions executed with automatic CSRF, input sanitization, DOM morphing & exception containment.\n";
$testsPassed++;

// -----------------------------------------------------------------------------
// Test 4: CLI Toolchain (nextphp page:make, security:audit, cache:compile, build:cpanel)
// -----------------------------------------------------------------------------
echo "[4/4] Testing NextPHP CLI toolchain commands...\n";

$cli = new Cli($baseDir);

// 4A. page:make command
$testRoute = 'unit-test-scaffold/subpage';
$exitMake = $cli->makePage([$testRoute, '--layout', '--force']);
assert($exitMake === 0, "page:make should return exit code 0");
$scaffoldPage = $baseDir . '/pages/' . $testRoute . '/page.php';
$scaffoldLayout = $baseDir . '/pages/' . $testRoute . '/layout.php';
assert(file_exists($scaffoldPage), "Scaffolded page.php must exist");
assert(file_exists($scaffoldLayout), "Scaffolded layout.php must exist");

$scaffoldContent = (string)file_get_contents($scaffoldPage);
assert(str_contains($scaffoldContent, '<Card'), "Scaffolded page should include JSX <Card> components");
assert(str_contains($scaffoldContent, '<Button'), "Scaffolded page should include JSX <Button> components");

// Clean up scaffolded test route
@unlink($scaffoldPage);
@unlink($scaffoldLayout);
@rmdir(dirname($scaffoldPage));
@rmdir(dirname(dirname($scaffoldPage)));

// 4B. cache:compile command
$exitCompile = $cli->compileCache();
assert($exitCompile === 0, "cache:compile should return exit code 0");
$compiledViews = glob($baseDir . '/storage/cache/views/view_*.php');
assert(!empty($compiledViews), "cache:compile must produce view_*.php files");

// 4C. security:audit command
$exitAudit = $cli->securityAudit();
assert($exitAudit === 0, "security:audit must return exit code 0 on fortified project");

// 4D. build:cpanel command
$exitBuild = $cli->buildCpanel([]);
assert($exitBuild === 0, "build:cpanel should return exit code 0");
$cpanelZip = $baseDir . '/dist/nexus-cpanel-production.zip';
assert(file_exists($cpanelZip), "dist/nexus-cpanel-production.zip must exist");
assert(filesize($cpanelZip) > 10000, "cPanel zip must be a non-empty archive (>10KB)");

echo "  \033[1;32m-> PASS:\033[0m All CLI commands (page:make, security:audit, cache:compile, build:cpanel) verified.\n";
$testsPassed++;

// Cleanup test pages directory
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testPagesDir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $file) {
    if ($file->isDir()) {
        rmdir($file->getPathname());
    } else {
        unlink($file->getPathname());
    }
}
rmdir($testPagesDir);

echo "\033[1;34m========================================================\033[0m\n";
echo "\033[1;32mSTEP 6 COMPLETED: ALL {$testsPassed} TEST PHASES PASSED (100% HEALTHY)!\033[0m\n";
echo "\033[1;34m========================================================\033[0m\n\n";
