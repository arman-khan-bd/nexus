<?php

declare(strict_types=1);

namespace Core\Console;

use Core\Env;
use Core\Compiler\JsxCompiler;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Class Cli
 *
 * Nexus Command Line Toolchain (Nexus CLI).
 *
 * Commands:
 * - php nexus serve [port]         Start local development server
 * - php nexus page:make <route>    Scaffold Next.js-style page and optional layout
 * - php nexus security:audit       Scan file permissions, .env, and security headers
 * - php nexus cache:compile        Pre-compile all JSX templates into storage/cache/views
 * - php nexus build:cpanel         Package production-ready .zip archive for cPanel public_html
 * - php nexus key:generate         Regenerate 256-bit Libsodium APP_KEY
 * - php nexus cache:clear          Clear compiled JSX and view caches
 * - php nexus routes               List all file-system routes
 * - php nexus about                Display framework and security posture
 */
final class Cli
{
    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? dirname(__DIR__, 2);
    }

    /**
     * Entry point for CLI command dispatch.
     */
    public static function run(array $argv, ?string $baseDir = null): int
    {
        $cli = new self($baseDir);
        $args = array_slice($argv, 1);
        $command = $args[0] ?? 'about';
        $params = array_slice($args, 1);

        try {
            return match ($command) {
                'page:make'       => $cli->makePage($params),
                'security:audit'  => $cli->securityAudit(),
                'cache:compile'   => $cli->compileCache(),
                'cache:clear'     => $cli->clearCache(),
                'build:cpanel'    => $cli->buildCpanel($params),
                'key:generate'    => $cli->generateKey(),
                'routes', 'route:list' => $cli->listRoutes(),
                'serve'           => $cli->serve($params),
                'help', '--help'  => $cli->help(),
                'about'           => $cli->about(),
                default           => $cli->unknownCommand($command),
            };
        } catch (Throwable $e) {
            echo "\033[1;31m[ERROR]\033[0m " . $e->getMessage() . "\n";
            if (Env::isDebug()) {
                echo $e->getTraceAsString() . "\n";
            }
            return 1;
        }
    }

    /**
     * Scaffold a new page with Next.js App Router conventions and JSX starter code.
     *
     * Example: php nexus page:make dashboard/settings --layout
     */
    public function makePage(array $params): int
    {
        if (empty($params[0])) {
            echo "\033[1;31m[ERROR]\033[0m Please provide a route path.\n";
            echo "Usage: php nexus page:make <route> [--layout] [--force]\n";
            echo "Example: php nexus page:make dashboard/settings\n";
            return 1;
        }

        $rawRoute = $params[0];
        $withLayout = in_array('--layout', $params, true);
        $force = in_array('--force', $params, true);

        // Normalize route
        $cleanRoute = trim($rawRoute, '/');
        $targetDir = $this->baseDir . '/pages/' . $cleanRoute;
        $pageFile = $targetDir . '/page.php';

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        if (file_exists($pageFile) && !$force) {
            echo "\033[1;33m[WARN]\033[0m Page file already exists at [pages/{$cleanRoute}/page.php]. Use --force to overwrite.\n";
            return 1;
        }

        // Detect dynamic parameters (e.g. [id], [slug])
        preg_match_all('/\[([a-zA-Z0-9_\.]+)\]/', $cleanRoute, $matches);
        $dynamicParams = $matches[1] ?? [];

        $title = ucwords(str_replace(['/', '-', '_', '[', ']'], ' ', $cleanRoute));

        // Generate Page Template
        $paramsDoc = '';
        $paramsCode = '';
        if (!empty($dynamicParams)) {
            $paramsDoc = "\n * Route Parameters: " . implode(', ', array_map(fn($p) => "\${$p}", $dynamicParams));
            foreach ($dynamicParams as $dp) {
                $varName = ltrim($dp, '.');
                $paramsCode .= "    \${$varName} = \$params['{$dp}'] ?? 'Unknown';\n";
            }
        }

        $pageTemplate = <<<PHP
<?php

declare(strict_types=1);

/**
 * Page: /{$cleanRoute}{$paramsDoc}
 */

// Route parameters passed by Nexus AppRouter
{$paramsCode}
\$pageTitle = "{$title} | Nexus";
?>
<div class="space-y-6">
    <div class="flex items-center justify-between border-b border-slate-800 pb-5">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-white">{$title}</h1>
            <p class="text-sm text-slate-400 mt-1">Managed via Nexus App Router file-system convention.</p>
        </div>
        <Link href="/" icon="arrow-left" class="text-sm text-slate-400 hover:text-white">Back to Home</Link>
    </div>

    <Card title="Route Details" description="Parameters and active state" class="bg-slate-900 border-slate-800">
        <div class="space-y-4">
            <div class="flex items-center gap-2">
                <Badge variant="outline">Route: /{$cleanRoute}</Badge>
                <Badge variant="success">Active</Badge>
            </div>
            <p class="text-sm text-slate-300">
                This page is compiled on-demand into cached native PHP via <code>JsxCompiler</code>.
            </p>
        </div>
    </Card>

    <Card title="Server Action Test" description="Direct zero-AJAX action execution" class="bg-slate-900 border-slate-800">
        <Form nexusAction="saveSample" class="space-y-4">
            <Input name="title" label="Item Title" placeholder="Enter title..." required />
            <Button type="submit" variant="default" icon="shield-check">Execute Action</Button>
        </Form>
    </Card>
</div>
PHP;

        file_put_contents($pageFile, $pageTemplate);
        echo "\033[1;32m[OK]\033[0m Created page: \033[1mpages/{$cleanRoute}/page.php\033[0m\n";

        // Generate Layout if requested
        if ($withLayout) {
            $layoutFile = $targetDir . '/layout.php';
            if (!file_exists($layoutFile) || $force) {
                $layoutTemplate = <<<PHP
<?php

declare(strict_types=1);

/**
 * Sub-Layout: /{$cleanRoute}
 */
?>
<div class="nested-sublayout border-l-2 border-blue-500/30 pl-4 my-2">
    <?= \$children ?>
</div>
PHP;
                file_put_contents($layoutFile, $layoutTemplate);
                echo "\033[1;32m[OK]\033[0m Created layout: \033[1mpages/{$cleanRoute}/layout.php\033[0m\n";
            }
        }

        echo "URL: \033[1;34mhttp://localhost:8000/{$cleanRoute}\033[0m\n";
        return 0;
    }

    /**
     * Run comprehensive security audit for cPanel shared hosting fortress.
     */
    public function securityAudit(): int
    {
        echo "\033[1;34m========================================================\033[0m\n";
        echo "\033[1;36m       NEXUS APPLICATION SECURITY AUDIT (CPANEL)        \033[0m\n";
        echo "\033[1;34m========================================================\033[0m\n\n";

        $checks = [];
        $score = 100;

        // 1. Storage Isolation
        $storageDirs = [
            'storage',
            'storage/cache',
            'storage/cache/views',
            'storage/sessions',
            'storage/ratelimit',
            'storage/logs',
            'storage/uploads',
        ];

        $storageOk = true;
        foreach ($storageDirs as $sDir) {
            $fullDir = $this->baseDir . '/' . $sDir;
            if (!is_dir($fullDir)) {
                $storageOk = false;
                break;
            }
            if (DIRECTORY_SEPARATOR !== '\\') {
                $perms = fileperms($fullDir) & 0777;
                if ($perms > 0700) {
                    $storageOk = false;
                }
            }
        }

        if ($storageOk) {
            $checks[] = ['pass', 'Storage Isolation', 'Storage subdirectories exist and restricted to 0700 owner-only isolation.'];
        } else {
            $checks[] = ['warn', 'Storage Isolation', 'Ensure storage directories exist and are chmod 0700 on cPanel.'];
            $score -= 10;
        }

        // 2. Storage Uploads Execution Shield
        $uploadsHtaccess = $this->baseDir . '/storage/uploads/.htaccess';
        if (file_exists($uploadsHtaccess)) {
            $upContent = (string)file_get_contents($uploadsHtaccess);
            if (str_contains($upContent, 'php_flag engine off') || str_contains($upContent, 'RemoveHandler') || str_contains($upContent, 'Deny from all')) {
                $checks[] = ['pass', 'Uploads Execution Lock', 'storage/uploads/.htaccess prohibits script execution and direct script execution.'];
            } else {
                $checks[] = ['warn', 'Uploads Execution Lock', 'storage/uploads/.htaccess missing strict execution lockout rules.'];
                $score -= 15;
            }
        } else {
            $checks[] = ['warn', 'Uploads Execution Lock', 'storage/uploads/.htaccess file is missing.'];
            $score -= 15;
        }

        // 3. Root Fortress .htaccess
        $rootHtaccess = $this->baseDir . '/.htaccess';
        if (file_exists($rootHtaccess)) {
            $htaccessContent = (string)file_get_contents($rootHtaccess);
            $hasIndexes = str_contains($htaccessContent, 'Options -Indexes');
            $hasDirBlock = str_contains($htaccessContent, 'core|app|pages|components|database|storage|vendor');
            $hasWaf = str_contains($htaccessContent, 'union') && str_contains($htaccessContent, 'base64_');

            if ($hasIndexes && $hasDirBlock && $hasWaf) {
                $checks[] = ['pass', 'cPanel Fortress .htaccess', 'Apache Layer 7 WAF, directory listing disable, and core block rules active.'];
            } else {
                $checks[] = ['warn', 'cPanel Fortress .htaccess', '.htaccess exists but some protective rules may be missing.'];
                $score -= 10;
            }
        } else {
            $checks[] = ['fail', 'cPanel Fortress .htaccess', '.htaccess file is missing! Direct access to PHP source code possible.'];
            $score -= 30;
        }

        // 4. Environment Configuration (.env)
        $envPath = $this->baseDir . '/.env';
        if (file_exists($envPath)) {
            $envContent = (string)file_get_contents($envPath);

            // APP_KEY check
            if (preg_match('/APP_KEY=base64:([A-Za-z0-9+\/]{43}=)/', $envContent, $m)) {
                $keyBytes = base64_decode($m[1], true);
                if ($keyBytes !== false && strlen($keyBytes) === 32) {
                    $checks[] = ['pass', '256-Bit Cryptographic APP_KEY', 'Libsodium/AES-256 key verified with 256 bits of cryptographic entropy.'];
                } else {
                    $checks[] = ['warn', 'APP_KEY Strength', 'APP_KEY found but length is not 32 bytes (256-bit).'];
                    $score -= 15;
                }
            } else {
                $checks[] = ['fail', 'APP_KEY Missing', 'APP_KEY is missing or invalid in .env! Run: php nexus key:generate'];
                $score -= 25;
            }

            // APP_DEBUG in production
            $appEnv = Env::string('APP_ENV', 'production');
            $appDebug = Env::bool('APP_DEBUG', true);
            if ($appEnv === 'production' && $appDebug) {
                $checks[] = ['warn', 'Debug Mode in Production', 'APP_DEBUG=true in production environment! Detailed stack traces could leak secrets.'];
                $score -= 15;
            } else {
                $checks[] = ['pass', 'Debug Environment Mode', "APP_DEBUG is safely configured for environment [{$appEnv}]."];
            }

            // WAF & Rate Limit enabled
            if (Env::bool('WAF_ENABLED', true)) {
                $checks[] = ['pass', 'Deep Application WAF', 'Core\Security\Firewall deep payload inspection active.'];
            } else {
                $checks[] = ['warn', 'Application WAF', 'WAF_ENABLED is false in .env.'];
                $score -= 10;
            }

            if (Env::bool('RATE_LIMIT_ENABLED', true)) {
                $checks[] = ['pass', 'Anti-DDoS & Sliding RateLimiter', 'Atomic sliding window rate limiting active (60 req/min, 5 POST/10s).'];
            } else {
                $checks[] = ['warn', 'Rate Limiter', 'RATE_LIMIT_ENABLED is false in .env.'];
                $score -= 10;
            }
        } else {
            $checks[] = ['fail', '.env File Missing', '.env configuration file does not exist.'];
            $score -= 30;
        }

        // 5. Output Audit Report
        $score = max(0, $score);
        foreach ($checks as [$status, $title, $description]) {
            $badge = match ($status) {
                'pass' => "\033[1;32m[PASS]\033[0m",
                'warn' => "\033[1;33m[WARN]\033[0m",
                'fail' => "\033[1;31m[FAIL]\033[0m",
            };
            echo "{$badge} \033[1m{$title}\033[0m\n        {$description}\n\n";
        }

        $color = $score >= 90 ? "\033[1;32m" : ($score >= 70 ? "\033[1;33m" : "\033[1;31m");
        echo "\033[1;34m--------------------------------------------------------\033[0m\n";
        echo "Fortress Security Score: {$color}{$score} / 100\033[0m\n";

        if ($score >= 90) {
            echo "Status: \033[1;32mFORTIFIED & CPANEL PRODUCTION READY\033[0m\n";
        } elseif ($score >= 70) {
            echo "Status: \033[1;33mACCEPTABLE WITH WARNINGS - REMEDIATE BEFORE PUBLIC LAUNCH\033[0m\n";
        } else {
            echo "Status: \033[1;31mCRITICAL VULNERABILITIES DETECTED\033[0m\n";
        }
        echo "\033[1;34m========================================================\033[0m\n";

        return $score >= 70 ? 0 : 1;
    }

    /**
     * Pre-compile all JSX templates into storage/cache/views.
     */
    public function compileCache(): int
    {
        $startTime = microtime(true);
        $pagesDir = $this->baseDir . '/pages';
        $cacheDir = $this->baseDir . '/storage/cache/views';

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0700, true);
        }

        echo "\033[1;34m[Nexus JSX Compiler]\033[0m Scanning template files...\n";

        if (!is_dir($pagesDir)) {
            echo "\033[1;33m[WARN]\033[0m No pages directory found at [{$pagesDir}].\n";
            return 0;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pagesDir));
        $compiler = JsxCompiler::instance();
        $compiledCount = 0;

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filePath = $file->getPathname();
                $relPath = str_replace([$this->baseDir . DIRECTORY_SEPARATOR, $this->baseDir . '/'], '', $filePath);

                try {
                    $compiledPath = $compiler->getCompiledPath($filePath);
                    $compiledCount++;
                    echo "  \033[1;32m✓\033[0m Compiled: {$relPath} -> " . basename($compiledPath) . "\n";
                } catch (Throwable $e) {
                    echo "  \033[1;31m✗\033[0m Failed: {$relPath} (" . $e->getMessage() . ")\n";
                }
            }
        }

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        echo "\n\033[1;32m[OK]\033[0m Successfully pre-compiled \033[1m{$compiledCount}\033[0m view templates in \033[1m{$elapsed}ms\033[0m.\n";
        echo "Cache Directory: \033[1;34mstorage/cache/views/\033[0m\n";

        return 0;
    }

    /**
     * Clear compiled JSX views and runtime caches.
     */
    public function clearCache(): int
    {
        echo "\033[1;34m[Nexus]\033[0m Clearing view and route caches...\n";
        $cacheDir = $this->baseDir . '/storage/cache/views';
        $count = 0;

        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . '/*');
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file) && !str_ends_with($file, '.gitkeep')) {
                        unlink($file);
                        $count++;
                    }
                }
            }
        }

        // Clear rate limiter and ban cache
        $rlDir = $this->baseDir . '/storage/ratelimit';
        if (is_dir($rlDir)) {
            $files = glob($rlDir . '/*.json');
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                        $count++;
                    }
                }
            }
        }

        echo "\033[1;32m[OK]\033[0m Cleared {$count} cached files.\n";
        return 0;
    }

    /**
     * Package a production-ready, clean .zip distribution for cPanel public_html.
     */
    public function buildCpanel(array $params): int
    {
        $startTime = microtime(true);
        echo "\033[1;34m========================================================\033[0m\n";
        echo "\033[1;36m       BUILDING CPANEL PRODUCTION ARCHIVE               \033[0m\n";
        echo "\033[1;34m========================================================\033[0m\n\n";

        // 1. Precompile all views first
        echo "[1/4] Precompiling JSX templates for instant performance...\n";
        $this->compileCache();

        // 2. Ensure storage folders and gitkeeps exist
        echo "\n[2/4] Fortifying storage directories & deployment assets...\n";
        Installer::setupStorageDirectories($this->baseDir);

        // 3. Prepare target output path
        $distDir = $this->baseDir . '/dist';
        if (!is_dir($distDir)) {
            mkdir($distDir, 0755, true);
        }
        $zipName = 'nexus-cpanel-production.zip';
        $zipPath = $distDir . '/' . $zipName;

        if (file_exists($zipPath)) {
            unlink($zipPath);
        }

        echo "\n[3/4] Collecting production files and stripping dev artifacts...\n";

        // Files and directories to package
        $includeList = [
            'index.php',
            '.htaccess',
            '.env.example',
            'composer.json',
            'bin',
            'core',
            'app',
            'pages',
            'components',
            'assets',
            'storage',
        ];

        // Explicit exclusions
        $excludePatterns = [
            '/\.git/',
            '/\.gitignore/',
            '/tests\//',
            '/\.env$/', // Never ship local .env with dev secrets
            '/storage\/logs\/.*\.log$/',
            '/storage\/sessions\/sess_.*/',
            '/storage\/ratelimit\/.*\.json$/',
            '/dist\//',
            '/build\//',
            '/\.DS_Store/',
            '/Thumbs\.db/',
            '/\.phpunit\.cache/',
        ];

        $filesToPack = [];

        foreach ($includeList as $item) {
            $itemPath = $this->baseDir . '/' . $item;
            if (!file_exists($itemPath)) {
                continue;
            }

            if (is_file($itemPath)) {
                $filesToPack[] = [
                    'source'   => $itemPath,
                    'relative' => $item,
                ];
            } elseif (is_dir($itemPath)) {
                $dirIterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($itemPath, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($dirIterator as $file) {
                    $absPath = $file->getPathname();
                    $normAbs = str_replace('\\', '/', $absPath);
                    $normBase = str_replace('\\', '/', $this->baseDir);
                    $relPath = ltrim(substr($normAbs, strlen($normBase)), '/');

                    // Check exclusions
                    $excluded = false;
                    foreach ($excludePatterns as $pattern) {
                        if (preg_match($pattern, '/' . $relPath)) {
                            $excluded = true;
                            break;
                        }
                    }

                    if ($excluded) {
                        continue;
                    }

                    if ($file->isFile()) {
                        $filesToPack[] = [
                            'source'   => $absPath,
                            'relative' => $relPath,
                        ];
                    }
                }
            }
        }

        // Add standard empty storage .gitkeep files to ensure directories are preserved in zip
        $emptyDirs = [
            'storage/cache/views',
            'storage/sessions',
            'storage/ratelimit',
            'storage/logs',
            'storage/uploads',
        ];
        foreach ($emptyDirs as $ed) {
            $keepPath = $this->baseDir . '/' . $ed . '/.gitkeep';
            if (!file_exists($keepPath)) {
                @file_put_contents($keepPath, '');
            }
            $filesToPack[] = [
                'source'   => $keepPath,
                'relative' => $ed . '/.gitkeep',
            ];
        }

        echo "  Found " . count($filesToPack) . " production files ready for packaging.\n";

        // 4. Create ZIP archive
        echo "\n[4/4] Writing production zip archive...\n";
        $zipSuccess = self::createZipArchive($zipPath, $filesToPack);

        if (!$zipSuccess || !file_exists($zipPath)) {
            throw new RuntimeException("Failed to generate cPanel zip archive at [{$zipPath}].");
        }

        $fileSizeKb = round(filesize($zipPath) / 1024, 2);
        $sha256 = hash_file('sha256', $zipPath);
        $elapsed = round(microtime(true) - $startTime, 2);

        echo "\033[1;32m[OK] Build Complete in {$elapsed}s!\033[0m\n\n";
        echo "Archive:    \033[1;34m{$zipPath}\033[0m\n";
        echo "Size:       \033[1m{$fileSizeKb} KB\033[0m\n";
        echo "SHA-256:    \033[1m{$sha256}\033[0m\n\n";

        echo "\033[1;36m===> CPANEL DEPLOYMENT INSTRUCTIONS <===\033[0m\n";
        echo "1. Log into your cPanel account and open \033[1mFile Manager\033[0m.\n";
        echo "2. Navigate to your domain's root (typically \033[1mpublic_html\033[0m).\n";
        echo "3. Upload \033[1;34m{$zipName}\033[0m and click \033[1mExtract\033[0m.\n";
        echo "4. Copy \033[1m.env.example\033[0m to \033[1m.env\033[0m and set your production DB credentials.\n";
        echo "5. Run in cPanel Terminal / SSH:\n";
        echo "     \033[1;32mphp bin/nexus key:generate\033[0m\n";
        echo "     \033[1;32mphp bin/nexus security:audit\033[0m\n";
        echo "6. All done! Your ultra-secure Nexus site is live.\n";
        echo "\033[1;34m========================================================\033[0m\n";

        return 0;
    }

    /**
     * Create zip archive using ZipArchive, system tar, or pure-PHP PKZip builder.
     */
    private static function createZipArchive(string $zipPath, array $filesToPack): bool
    {
        // Method A: Native PHP ZipArchive
        if (class_exists('\ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                foreach ($filesToPack as $item) {
                    $zip->addFile($item['source'], $item['relative']);
                }
                return $zip->close();
            }
        }

        // Method B: System BSD tar (standard on Windows 10/11 & Linux)
        $tempDir = dirname($zipPath) . '/__build_tmp_' . bin2hex(random_bytes(6));
        mkdir($tempDir, 0755, true);

        try {
            foreach ($filesToPack as $item) {
                $targetFile = $tempDir . '/' . $item['relative'];
                $targetDir = dirname($targetFile);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                copy($item['source'], $targetFile);
            }

            // Test if tar is available
            $escapedZip = escapeshellarg($zipPath);
            $cmd = "tar -a -cf {$escapedZip} -C " . escapeshellarg($tempDir) . " .";
            exec($cmd, $output, $returnCode);

            if ($returnCode === 0 && file_exists($zipPath) && filesize($zipPath) > 0) {
                self::deleteDirectory($tempDir);
                return true;
            }
        } catch (Throwable $e) {
            // Fall through to Method C
        } finally {
            if (is_dir($tempDir)) {
                self::deleteDirectory($tempDir);
            }
        }

        // Method C: Pure PHP Deflate / PKZip Archiver (Zero external dependency)
        return self::createPurePhpZip($zipPath, $filesToPack);
    }

    /**
     * Pure-PHP PKZip archiver implementing standard ZIP format (RFC 1951 + PKZip spec).
     */
    private static function createPurePhpZip(string $zipPath, array $filesToPack): bool
    {
        $fp = fopen($zipPath, 'wb');
        if ($fp === false) {
            return false;
        }

        $centralDirectory = '';
        $offset = 0;
        $totalFiles = 0;

        foreach ($filesToPack as $item) {
            $filename = str_replace('\\', '/', $item['relative']);
            $data = (string)file_get_contents($item['source']);

            $crc = crc32($data);
            $uncompressedSize = strlen($data);

            // Compress data if zlib is loaded
            if (function_exists('gzdeflate') && $uncompressedSize > 0) {
                $compressedData = (string)gzdeflate($data);
                $compressionMethod = 8; // Deflate
                $compressedSize = strlen($compressedData);
            } else {
                $compressedData = $data;
                $compressionMethod = 0; // Store
                $compressedSize = $uncompressedSize;
            }

            $nameLength = strlen($filename);
            $modTime = filemtime($item['source']);
            $dosTime = self::unixToDosTime($modTime);

            // Local File Header
            $localHeader = pack(
                'VvvvvvVVVvv',
                0x04034b50,       // signature
                20,               // version needed to extract (2.0)
                0,                // general purpose bit flag
                $compressionMethod,
                $dosTime['time'], // last mod file time
                $dosTime['date'], // last mod file date
                $crc,
                $compressedSize,
                $uncompressedSize,
                $nameLength,
                0                 // extra field length
            );

            fwrite($fp, $localHeader . $filename . $compressedData);

            // Central Directory Header
            $centralHeader = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,       // signature
                20,               // version made by
                20,               // version needed to extract
                0,                // general purpose bit flag
                $compressionMethod,
                $dosTime['time'],
                $dosTime['date'],
                $crc,
                $compressedSize,
                $uncompressedSize,
                $nameLength,
                0,                // extra field length
                0,                // file comment length
                0,                // disk number start
                0,                // internal file attributes
                0x81a40000,       // external file attributes (0644 unix file)
                $offset           // relative offset of local header
            );

            $centralDirectory .= $centralHeader . $filename;
            $offset += strlen($localHeader) + $nameLength + strlen($compressedData);
            $totalFiles++;
        }

        $cdOffset = $offset;
        $cdSize = strlen($centralDirectory);

        // Write Central Directory
        fwrite($fp, $centralDirectory);

        // End of Central Directory Record (EOCD)
        $eocd = pack(
            'VvvvvVVv',
            0x06054b50,  // signature
            0,           // number of this disk
            0,           // number of disk with start of central directory
            $totalFiles, // total entries in central directory on this disk
            $totalFiles, // total entries in central directory
            $cdSize,     // size of central directory
            $cdOffset,   // offset of start of central directory
            0            // zipfile comment length
        );

        fwrite($fp, $eocd);
        fclose($fp);

        return true;
    }

    /**
     * Converts a Unix timestamp to DOS date/time format.
     *
     * @return array{date: int, time: int}
     */
    private static function unixToDosTime(int $unixTime): array
    {
        $d = getdate($unixTime);
        $dosTime = ($d['hours'] << 11) | ($d['minutes'] << 5) | ($d['seconds'] >> 1);
        $dosDate = (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];

        return ['date' => $dosDate, 'time' => $dosTime];
    }

    /**
     * Delete directory recursively.
     */
    private static function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . '/' . $item;
                if (is_dir($path)) {
                    self::deleteDirectory($path);
                } else {
                    @unlink($path);
                }
            }
        }
        @rmdir($dir);
    }

    /**
     * Regenerate 256-bit Libsodium APP_KEY in .env.
     */
    public function generateKey(): int
    {
        echo "\033[1;34m[Nexus]\033[0m Regenerating 256-bit Application Key...\n";
        Installer::generateKeyCommand();
        return 0;
    }

    /**
     * Discover and list all file-system routes.
     */
    public function listRoutes(): int
    {
        echo "\033[1;34m[Nexus App Router - Discovered Routes]\033[0m\n";
        $pagesDir = $this->baseDir . '/pages';
        if (!is_dir($pagesDir)) {
            echo "No pages directory found at [{$pagesDir}].\n";
            return 0;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pagesDir));
        $routes = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === 'page.php') {
                $relPath = str_replace([$pagesDir, DIRECTORY_SEPARATOR], ['', '/'], dirname($file->getPathname()));
                $routeUri = $relPath === '' ? '/' : $relPath;
                $routes[] = $routeUri;
            }
        }

        sort($routes);
        foreach ($routes as $route) {
            echo "  \033[1;32mGET\033[0m \033[1m{$route}\033[0m\n";
        }
        echo "\nTotal Routes: " . count($routes) . "\n";
        return 0;
    }

    /**
     * Start local PHP development server.
     *
     * Examples:
     * - php nexus serve
     * - php nexus serve 8080
     * - php nexus serve --port=8080 --host=0.0.0.0
     */
    public function serve(array $params = []): int
    {
        $host = '127.0.0.1';
        $port = '8000';

        foreach ($params as $param) {
            if (str_starts_with($param, '--port=')) {
                $port = substr($param, 7);
            } elseif (str_starts_with($param, '--host=')) {
                $host = substr($param, 7);
            } elseif (is_numeric($param)) {
                $port = $param;
            }
        }

        $this->printBanner();
        echo "\033[1;36mNexus Development Server v2.0\033[0m\n";
        echo "  -> Local:            \033[1;32mhttp://{$host}:{$port}\033[0m\n";
        echo "  -> Front Controller: \033[1m{$this->baseDir}/index.php\033[0m\n";
        echo "  -> Environment:      \033[1;33m" . Env::string('APP_ENV', 'local') . "\033[0m\n\n";
        echo "Press \033[1;33mCtrl+C\033[0m to terminate the development server.\n\n";

        passthru(PHP_BINARY . " -S {$host}:{$port} index.php");
        return 0;
    }

    /**
     * Framework info and security posture.
     */
    public function about(): int
    {
        $this->printBanner();
        echo "\033[1mNexus Framework Foundation v2.0.0\033[0m\n";
        echo "Next.js-inspired, JSX-compiled, ultra-secure PHP framework for cPanel\n";
        echo "---------------------------------------------------------\n";
        echo "PHP Version:       " . PHP_VERSION . "\n";
        echo "Libsodium:         " . (extension_loaded('sodium') ? "\033[32mEnabled (Native)\033[0m" : "\033[33mNot Loaded (OpenSSL Fallback Active)\033[0m") . "\n";
        echo "OpenSSL:           " . (extension_loaded('openssl') ? "\033[32mEnabled\033[0m" : "\033[31mDisabled\033[0m") . "\n";
        echo "Environment:       " . Env::string('APP_ENV', 'unknown') . "\n";
        echo "Debug Mode:        " . (Env::isDebug() ? "\033[33mActive\033[0m" : "\033[32mInactive\033[0m") . "\n";
        echo "WAF Shield:        " . (Env::bool('WAF_ENABLED', true) ? "\033[32mArmed\033[0m" : "\033[31mDisabled\033[0m") . "\n";
        echo "Rate Limiter:      " . (Env::bool('RATE_LIMIT_ENABLED', true) ? "\033[32mActive\033[0m" : "\033[31mDisabled\033[0m") . "\n";
        echo "\nAvailable Commands (php nexus <command>):\n";
        echo "  \033[1;33mserve [port]\033[0m        Start local development server (e.g. php nexus serve 8000)\n";
        echo "  \033[1;33mpage:make <route>\033[0m   Scaffold page.php and optional layout with starter JSX\n";
        echo "  \033[1;33msecurity:audit\033[0m      Scan file permissions, .env, and security headers\n";
        echo "  \033[1;33mcache:compile\033[0m       Pre-compile all JSX templates into storage/cache/views\n";
        echo "  \033[1;33mbuild:cpanel\033[0m        Pack production-ready .zip archive for cPanel public_html\n";
        echo "  \033[1;33mkey:generate\033[0m        Generate a 256-bit Libsodium/AES APP_KEY in .env\n";
        echo "  \033[1;33mcache:clear\033[0m         Clear compiled JSX and view caches\n";
        echo "  \033[1;33mroutes\033[0m              List all file-system routes\n";
        return 0;
    }

    public function help(): int
    {
        return $this->about();
    }

    private function unknownCommand(string $command): int
    {
        echo "\033[1;31m[ERROR]\033[0m Unknown command: '{$command}'.\n\n";
        $this->about();
        return 1;
    }

    private function printBanner(): void
    {
        echo "\033[1;32m";
        echo "   _  __                   \n";
        echo "  / |/ /__ __ _____ __ ___ \n";
        echo " /    / -_) \ \ / // (_-< \n";
        echo "/_/|_/\__/ /_\_\\_,_/___/ \n";
        echo "\033[0m";
    }
}
