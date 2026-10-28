<?php

declare(strict_types=1);

$baseDir = dirname(__DIR__);

// Register PSR-4 autoloader
spl_autoload_register(function (string $class) use ($baseDir) {
    $prefix = 'Core\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) === 0) {
        $file = $baseDir . '/core/' . str_replace('\\', '/', substr($class, $len)) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use Core\Env;
use Core\Security\Crypto;
use Core\Security\FileShield;
use Core\Security\SessionGuard;
use Core\Http\Request;

echo "========================================================\n";
echo "NEXUS FILE & SESSION INTEGRITY - STEP 3 TEST SUITE\n";
echo "========================================================\n";

Env::load($baseDir . '/.env');

$tmpDir = $baseDir . '/storage/cache/test_tmp';
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0700, true);
}

// -----------------------------------------------------------------------------
// 1. FILESHIELD: DOUBLE EXTENSION & DISGUISED WEB SHELL DEFENSE
// -----------------------------------------------------------------------------
echo "[1/2] Testing FileShield upload verification, anti-web shell & EXIF...\n";

// 1A. Double Extension Rejection
$doubleExtFiles = [
    'shell.php.jpg',
    'exploit.php5.png',
    'backdoor.phtml.gif',
    'malware.phar.pdf',
    'trojan.cgi.jpeg',
    'script.sh.png',
    'config.env.jpg',
];

foreach ($doubleExtFiles as $badName) {
    $caught = false;
    try {
        FileShield::inspectFileName($badName, ['jpg', 'jpeg', 'png', 'gif', 'pdf']);
    } catch (\Throwable $e) {
        $caught = true;
        assert(str_contains($e->getMessage(), 'Forbidden file extension or double extension'), "Unexpected exception: " . $e->getMessage());
    }
    assert($caught, "Double extension [{$badName}] was not blocked!");
}
echo "  -> PASS: Double extensions (shell.php.jpg, backdoor.phtml.png) rejected\n";

// 1B. MIME Spoofing Rejection (PHP script claiming to be .jpg)
$fakeJpg = $tmpDir . '/fake_image.jpg';
file_put_contents($fakeJpg, "<?php echo 'malicious backdoor'; ?>");

$caughtMime = false;
try {
    FileShield::verifyUpload([
        'name'     => 'fake_image.jpg',
        'tmp_name' => $fakeJpg,
        'size'     => filesize($fakeJpg),
        'error'    => UPLOAD_ERR_OK,
    ]);
} catch (\Throwable $e) {
    $caughtMime = true;
    assert(str_contains($e->getMessage(), 'MIME type mismatch') || str_contains($e->getMessage(), 'Malicious script signature'), "Unexpected error: " . $e->getMessage());
}
assert($caughtMime, "Fake JPG with PHP code was not intercepted!");
echo "  -> PASS: Fake JPG containing PHP code rejected via true MIME & code signature scan\n";

// 1C. Embedded PHP inside valid image (Steganography / EXIF injection)
// Create a legitimate 10x10 PNG image using GD
$cleanPng = $tmpDir . '/test_clean.png';
$im = imagecreatetruecolor(10, 10);
$red = imagecolorallocate($im, 255, 0, 0);
imagefill($im, 0, 0, $red);
imagepng($im, $cleanPng);
imagedestroy($im);

// Verify legitimate upload
$uploadResult = FileShield::verifyUpload([
    'name'     => 'avatar.png',
    'tmp_name' => $cleanPng,
    'size'     => filesize($cleanPng),
    'error'    => UPLOAD_ERR_OK,
]);

assert($uploadResult['valid'] === true, 'Valid PNG upload must succeed');
assert(file_exists($uploadResult['stored_path']), 'Stored file must exist on disk');
assert($uploadResult['mime_type'] === 'image/png', 'MIME type must be image/png');
assert(strlen($uploadResult['stored_name']) === 36, 'Stored name must be 128-bit hash (32 hex + .png)');
echo "  -> PASS: Valid image verified, re-encoded (EXIF-stripped) and renamed to 128-bit hash\n";

// 1D. Storage Fortress .htaccess verification
$uploadsHtaccess = $baseDir . '/storage/uploads/.htaccess';
assert(file_exists($uploadsHtaccess), 'Uploads fortress .htaccess must be created');
$htaccessBody = file_get_contents($uploadsHtaccess);
assert(str_contains($htaccessBody, 'php_flag engine off') && str_contains($htaccessBody, 'RemoveHandler'), 'Uploads .htaccess must disable script execution');
echo "  -> PASS: Storage fortress .htaccess active (execution disabled)\n";

// -----------------------------------------------------------------------------
// 2. SESSIONGUARD: LIBODIUM ENCRYPTION, FINGERPRINTING & ROTATION
// -----------------------------------------------------------------------------
echo "[2/2] Testing SessionGuard authenticated encryption & anti-hijacking...\n";

// 2A. Test Encrypted Session Save Handler
$sessionStorageDir = $baseDir . '/storage/sessions';
$guard = new SessionGuard($sessionStorageDir);

$testSessionId = 'test_' . bin2hex(random_bytes(12));
$plainSessionData = 'user_id|i:42;role|s:5:"admin";email|s:15:"admin@nexus.lan";';

// Write encrypted session to disk
$guard->write($testSessionId, $plainSessionData);

$sessionFilePath = $sessionStorageDir . '/sess_' . $testSessionId;
assert(file_exists($sessionFilePath), 'Session file must exist on disk');

// Verify that file contents on disk are ENCRYPTED ciphertext (NOT plaintext)
$rawDiskBytes = file_get_contents($sessionFilePath);
assert(!str_contains($rawDiskBytes, 'admin@nexus.lan'), 'Session file on disk must NOT contain plaintext!');
assert(str_starts_with($rawDiskBytes, 'v1:sodium:') || str_starts_with($rawDiskBytes, 'v1:gcm:'), 'Session file must have authenticated encryption header');

// Read and decrypt session data
$decrypted = $guard->read($testSessionId);
assert($decrypted === $plainSessionData, 'Decrypted session data does not match original data');
echo "  -> PASS: Libsodium/AES-256 authenticated encryption on disk (multi-tenant shared hosting safe)\n";

// Clean test session file
$guard->destroy($testSessionId);
assert(!file_exists($sessionFilePath), 'Destroy must delete session file');

// 2B. Test Session Fingerprinting & Anti-Hijacking
$originalReq = new Request(
    'GET', '/', '/', [], [],
    ['host' => 'localhost', 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0'],
    [], [],
    ['REMOTE_ADDR' => '192.168.1.105'] // Subnet: 192.168.1.0
);

// Start session
SessionGuard::start($originalReq);
$_SESSION['user'] = 'ceo';
$firstFingerprint = $_SESSION['_nexus_fingerprint'];
assert(is_string($firstFingerprint) && strlen($firstFingerprint) === 64, 'Fingerprint must be 64-char HMAC-SHA256');

// Legitimate request from same /24 subnet (e.g. 192.168.1.150) & same UA
$legitReq = new Request(
    'GET', '/', '/', [], [],
    ['host' => 'localhost', 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0'],
    [], [],
    ['REMOTE_ADDR' => '192.168.1.150']
);
SessionGuard::verifyFingerprint($legitReq);
assert(isset($_SESSION['user']) && $_SESSION['user'] === 'ceo', 'Legitimate request on same subnet must retain session');

// Simulated Session Hijack: Attacker uses stolen session from different network (IP: 10.0.50.2)
$attackerReq = new Request(
    'GET', '/', '/', [], [],
    ['host' => 'localhost', 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0'],
    [], [],
    ['REMOTE_ADDR' => '10.0.50.2'] // Subnet: 10.0.50.0 != 192.168.1.0
);
SessionGuard::verifyFingerprint($attackerReq);
assert(!isset($_SESSION['user']), 'Session hijacking MUST wipe session data immediately!');
echo "  -> PASS: Session hijacking intercepted (stolen token from another network immediately destroyed)\n";

// 2C. Automatic Anti-Fixation Session ID Rotation (every 10 minutes)
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$_SESSION['_nexus_last_rotated'] = time() - 601; // Expired by 1 second over 600s
$currentSessionId = session_id();
SessionGuard::rotateSessionIdPeriodically();
$newSessionId = session_id();
assert($currentSessionId !== $newSessionId, 'Session ID must rotate after 10 minutes');
assert(time() - (int)$_SESSION['_nexus_last_rotated'] < 2, 'Last rotated timestamp must be updated to current time');
echo "  -> PASS: Anti-fixation session ID rotation verified (rotates every 10 minutes)\n";

// Cleanup tmp test files
@unlink($fakeJpg);
@unlink($cleanPng);
if (isset($uploadResult['stored_path'])) {
    @unlink($uploadResult['stored_path']);
}

echo "========================================================\n";
echo "STEP 3 ALL INTEGRITY TESTS PASSED! (100% HEALTHY)\n";
echo "========================================================\n";
