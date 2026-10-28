<?php

declare(strict_types=1);

$baseDir = dirname(__DIR__);

// Register PSR-4 autoloader
spl_autoload_register(function (string $class) use ($baseDir) {
    $prefixes = [
        'Core\\'       => $baseDir . '/core/',
        'App\\'        => $baseDir . '/app/',
        'Components\\' => $baseDir . '/components/',
    ];

    foreach ($prefixes as $prefix => $dir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, $len)) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});

use Core\Env;
use Core\Security\Escaper;
use Core\Security\Csrf;
use Core\Compiler\JsxCompiler;
use Core\UI\ComponentRegistry;
use Components\Ui\Button;
use Components\Ui\Card;
use Components\Ui\Input;
use Components\Ui\Badge;
use Components\Ui\Dialog;
use Components\Ui\Link;
use Components\Ui\Form;
use Components\Ui\Lucide;

echo "========================================================\n";
echo "NEXUS JSX COMPILER & SHADCN UI - STEP 5 TEST SUITE\n";
echo "========================================================\n";

Env::load($baseDir . '/.env');

// -----------------------------------------------------------------------------
// 1. ESCAPER CONTEXT-AWARE TESTS
// -----------------------------------------------------------------------------
echo "[1/3] Testing Escaper context-aware sanitization & protocol filtering...\n";

// 1A. HTML Context
$xss = '<script>alert("xss")</script>';
$escapedHtml = Escaper::html($xss);
assert($escapedHtml === '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', 'HTML escaping failed');

// 1B. Attribute Context
$attrBreakout = '" onmouseover="alert(1)';
$escapedAttr = Escaper::attr($attrBreakout);
assert(!str_contains($escapedAttr, '" onmouseover'), 'Attribute breakout unescaped');

// 1C. JavaScript Context (JSON_HEX)
$jsPayload = "test'</script><script>alert(1)</script>";
$escapedJs = Escaper::js($jsPayload);
assert(!str_contains($escapedJs, '</script>'), 'JS context failed to escape closing tag');
assert(str_contains($escapedJs, '\u003C'), 'JS context must use unicode hex tag escapes');

// 1D. URL Context & Malicious Protocol Blocking
$badUrls = [
    'javascript:alert(1)',
    'javascript:/*--></title></style></textarea><em><script>alert(1)</script>',
    'vbscript:msgbox(1)',
    'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
];
foreach ($badUrls as $bad) {
    assert(Escaper::url($bad) === '#', "Malicious URL [{$bad}] was not neutralized to #");
}

$goodUrls = [
    'https://nexus.dev/docs',
    'http://localhost:8000',
    '/dashboard/settings',
    './profile',
    '../posts',
    '?sort=desc',
    '#top',
    'mailto:security@nexus.dev',
];
foreach ($goodUrls as $good) {
    assert(Escaper::url($good) === $good, "Valid URL [{$good}] was wrongly modified");
}
echo "  -> PASS: Context-aware escaping (HTML, Attribute, JavaScript, URL protocol defense)\n";

// -----------------------------------------------------------------------------
// 2. JSX COMPILER SYNTAX TRANSFORMATION & DISK CACHING
// -----------------------------------------------------------------------------
echo "[2/3] Testing JsxCompiler tag parser, nested slots & mtime caching...\n";

$compiler = new JsxCompiler($baseDir . '/storage/cache/views');

// 2A. Expressions and Raw outputs
$rawTpl = '<div>{ $title } - {!! $rawHtml !!}</div>';
$compiledTpl = $compiler->compile($rawTpl);
assert(str_contains($compiledTpl, '\Core\Security\Escaper::html($title)'), 'Variable interpolation not compiled');
assert(str_contains($compiledTpl, '<?= $rawHtml ?>'), 'Raw directive not compiled');

// 2B. Self-closing Component Tag
$selfClosingTpl = '<Button variant="outline" icon="shield-check" disabled />';
$compiledSelf = $compiler->compile($selfClosingTpl);
assert(str_contains($compiledSelf, "ComponentRegistry::render('Button'"), 'Self-closing component not compiled');
assert(str_contains($compiledSelf, "'variant' => 'outline'"), 'Prop variant not parsed');
assert(str_contains($compiledSelf, "'icon' => 'shield-check'"), 'Prop icon not parsed');
assert(str_contains($compiledSelf, "'disabled' => true"), 'Boolean prop disabled not parsed');

// 2C. Block Component with Slot
$blockTpl = '<Card title="Overview"><p>Hello Nexus</p></Card>';
$compiledBlock = $compiler->compile($blockTpl);
assert(str_contains($compiledBlock, "ComponentRegistry::render('Card'"), 'Block component not compiled');
assert(str_contains($compiledBlock, 'Hello Nexus'), 'Slot content missing');

// 2D. Nested Components
$nestedTpl = '<Card title="Dashboard"><Button variant="default">Save</Button></Card>';
$compiledNested = $compiler->compile($nestedTpl);
assert(str_contains($compiledNested, "ComponentRegistry::render('Card'"), 'Outer component missing');
assert(str_contains($compiledNested, "ComponentRegistry::render('Button'"), 'Inner component missing');

// 2E. Live File Compilation & mtime Cache Invalidation
$testViewSource = $baseDir . '/storage/cache/test_jsx_view.php';
file_put_contents($testViewSource, '<h1>{ $greeting }</h1><Badge variant="success" pulse>Live</Badge>');

$cachedFile = JsxCompiler::compileFile($testViewSource);
assert(file_exists($cachedFile), 'Cached PHP file must exist on disk');

$renderedOutput = JsxCompiler::render($testViewSource, ['greeting' => 'Welcome to Nexus JSX!']);
assert(str_contains($renderedOutput, 'Welcome to Nexus JSX!'), 'Render output missing greeting');
assert(str_contains($renderedOutput, 'Live'), 'Render output missing Badge component output');
assert(str_contains($renderedOutput, 'animate-pulse'), 'Render output missing pulse badge');

@unlink($testViewSource);
echo "  -> PASS: JSX compiled into cached native PHP (nested components, props, expressions)\n";

// -----------------------------------------------------------------------------
// 3. SHADCN UI COMPONENT LIBRARY TESTS
// -----------------------------------------------------------------------------
echo "[3/3] Testing Shadcn UI Component Library (Button, Card, Input, Badge, Dialog, Link, Form, Lucide)...\n";

// 3A. Button
$button = new Button();
$btnHtml = $button->render(['variant' => 'destructive', 'size' => 'lg', 'icon' => 'trash'], fn() => 'Delete Item');
assert(str_contains($btnHtml, 'bg-red-600'), 'Destructive button variant missing red styling');
assert(str_contains($btnHtml, 'h-11 px-8'), 'Button large size classes missing');
assert(str_contains($btnHtml, 'data-lucide="trash"'), 'Button trash icon missing');
assert(str_contains($btnHtml, 'Delete Item'), 'Button slot content missing');

// Button as anchor
$btnAnchor = $button->render(['as' => 'a', 'href' => '/pricing'], fn() => 'Upgrade');
assert(str_starts_with($btnAnchor, '<a href="/pricing"'), 'Button as="a" failed');

// 3B. Card
$card = new Card();
$cardHtml = $card->render(['title' => 'Server Telemetry', 'description' => 'Real-time metrics', 'footer' => 'Updated 1m ago'], fn() => '<p>99.9% Uptime</p>');
assert(str_contains($cardHtml, 'Server Telemetry'), 'Card title missing');
assert(str_contains($cardHtml, 'Real-time metrics'), 'Card description missing');
assert(str_contains($cardHtml, '99.9% Uptime'), 'Card body missing');
assert(str_contains($cardHtml, 'Updated 1m ago'), 'Card footer missing');

// 3C. Input
$input = new Input();
$inputHtml = $input->render(['label' => 'Email Address', 'name' => 'email', 'placeholder' => 'user@example.com', 'error' => 'Invalid email format', 'icon' => 'mail']);
assert(str_contains($inputHtml, '<label for="email"'), 'Input label missing');
assert(str_contains($inputHtml, 'placeholder="user@example.com"'), 'Input placeholder missing');
assert(str_contains($inputHtml, 'data-lucide="mail"'), 'Input icon missing');
assert(str_contains($inputHtml, 'Invalid email format'), 'Input error message missing');

// 3D. Badge
$badge = new Badge();
$badgeHtml = $badge->render(['variant' => 'warning', 'pulse' => true], fn() => 'Caution');
assert(str_contains($badgeHtml, 'bg-amber-500/10'), 'Warning badge styling missing');
assert(str_contains($badgeHtml, 'animate-pulse'), 'Badge pulse missing');
assert(str_contains($badgeHtml, 'Caution'), 'Badge slot missing');

// 3E. Dialog (Alpine.js Modal)
$dialog = new Dialog();
$dialogHtml = $dialog->render(['title' => 'Confirm Deletion', 'description' => 'This action cannot be undone.', 'triggerText' => 'Open Modal'], fn() => '<p>Are you sure?</p>');
assert(str_contains($dialogHtml, 'x-data="{ open: false }"'), 'Dialog Alpine.js state missing');
assert(str_contains($dialogHtml, '@keydown.escape.window="open = false"'), 'Dialog ESC key trap missing');
assert(str_contains($dialogHtml, 'Confirm Deletion'), 'Dialog title missing');
assert(str_contains($dialogHtml, 'Are you sure?'), 'Dialog slot content missing');

// 3F. Link (Next.js SPA Link)
$link = new Link();
$linkHtml = $link->render(['href' => '/dashboard', 'icon' => 'arrow-right'], fn() => 'Go to Dashboard');
assert(str_contains($linkHtml, 'data-nexus-link'), 'Link must have data-nexus-link for pushState SPA morphing');
assert(str_contains($linkHtml, 'data-lucide="arrow-right"'), 'Link icon missing');

// 3G. Form with CSRF & Method Spoofing
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$form = new Form();
$formHtml = $form->render(['action' => '/api/users/1', 'method' => 'PUT', 'nexusAction' => 'updateUser'], fn() => '<button type="submit">Save</button>');
assert(str_contains($formHtml, 'name="_token"'), 'Form must inject CSRF hidden token');
assert(str_contains($formHtml, 'name="_method" value="PUT"'), 'Form must inject _method spoofing for PUT');
assert(str_contains($formHtml, 'name="_action" value="updateUser"'), 'Form must inject _action hidden field');

// 3H. Lucide Icon
$lucide = new Lucide();
$lucideHtml = $lucide->render(['name' => 'shield-check', 'class' => 'text-emerald-400']);
assert(str_contains($lucideHtml, 'data-lucide="shield-check"'), 'Lucide icon name missing');
assert(str_contains($lucideHtml, 'text-emerald-400'), 'Lucide custom class missing');

echo "  -> PASS: All 8 core Shadcn UI components rendered with Tailwind & Alpine.js tokens\n";

echo "========================================================\n";
echo "STEP 5 ALL COMPILER & UI TESTS PASSED! (100% HEALTHY)\n";
echo "========================================================\n";
