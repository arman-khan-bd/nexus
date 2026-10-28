<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Env;
use RuntimeException;
use InvalidArgumentException;

/**
 * Class FileShield
 *
 * Anti-Web Shell, Upload Sanitizer & File Integrity Guard for Nexus.
 *
 * Defends against:
 * - Double extension attacks (e.g. shell.php.jpg, backdoor.phtml.png)
 * - MIME spoofing via deep magic byte inspection (finfo_file)
 * - Steganographic and EXIF-injected web shells in images
 * - Path traversal in file names and directory placement
 * - Script execution in storage directories via fortress .htaccess isolation
 */
final class FileShield
{
    /**
     * Dangerous executable extensions blacklist that must NEVER appear
     * as the final extension or anywhere in intermediate dot-segments.
     */
    private static array $dangerousExtensions = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phtml',
        'phps', 'phar', 'inc', 'hphp', 'cgi', 'pl', 'py', 'sh', 'bash',
        'exe', 'bat', 'cmd', 'vbs', 'com', 'scr', 'msi', 'asp', 'aspx',
        'cer', 'asa', 'asax', 'jsp', 'jspx', 'jsw', 'jsv', 'jspf',
        'htaccess', 'htpasswd', 'env', 'ini', 'conf', 'config', 'yml', 'yaml',
    ];

    /**
     * Canonical mapping of file extensions to true MIME types.
     */
    private static array $mimeMap = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'],
        'json' => ['application/json', 'text/json', 'text/plain'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
    ];

    /**
     * Signatures indicative of embedded PHP web shells or script code.
     */
    private static array $webShellSignatures = [
        '/<\?php/i',
        '/<\?=/i',
        '/<\?[^a-z]/i',
        '/<script\s+language\s*=\s*["\']?php["\']?/i',
        '/__halt_compiler\s*\(/i',
        '/\b(?:eval|assert|passthru|shell_exec|system|popen|proc_open)\s*\(/i',
        '/base64_decode\s*\(/i',
        '/gzinflate\s*\(/i',
        '/str_rot13\s*\(/i',
    ];

    /**
     * Validate and sanitize an uploaded file.
     *
     * @param array|string $file $_FILES['input_name'] array or path to uploaded file.
     * @param array $options Configuration overrides.
     * @return array Metadata of the securely saved file.
     * @throws RuntimeException If upload fails or security violation is detected.
     */
    public static function verifyUpload(array|string $file, array $options = []): array
    {
        // 1. Resolve file info and temporary location
        $fileInfo = self::resolveUploadInfo($file);
        $originalName = $fileInfo['name'];
        $tmpPath = $fileInfo['tmp_name'];
        $fileSize = $fileInfo['size'];

        $allowedExtensions = $options['allowed_extensions'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
        $maxBytes = $options['max_bytes'] ?? (10 * 1024 * 1024); // 10MB default
        $destinationDir = $options['destination'] ?? (dirname(__DIR__, 2) . '/storage/uploads');
        $stripExif = $options['strip_exif'] ?? true;

        // 2. Validate file existence, readability, and size
        if (!file_exists($tmpPath) || !is_readable($tmpPath)) {
            self::logSecurityViolation('UPLOAD_NOT_READABLE', $originalName, 'Temporary file does not exist or cannot be read.');
            throw new RuntimeException("Uploaded file could not be read.");
        }

        if ($fileSize <= 0 || $fileSize > $maxBytes) {
            self::logSecurityViolation('UPLOAD_SIZE_EXCEEDED', $originalName, "Size {$fileSize} exceeds limit of {$maxBytes} bytes.");
            throw new RuntimeException("File size exceeds permitted maximum limit.");
        }

        // 3. Double Extension & Malicious Name Inspection
        $safeExt = self::inspectFileName($originalName, $allowedExtensions);

        // 4. True MIME Type Verification (Magic Byte Inspection)
        $trueMime = self::verifyTrueMimeType($tmpPath, $safeExt);

        // 5. Deep Binary Web Shell Code Scan
        self::scanForWebShellSignatures($tmpPath, $originalName);

        // 6. Ensure Destination Storage Directory and Fortress .htaccess
        self::ensureStorageFortress($destinationDir);

        // 7. Cryptographic Hash Renaming (128-bit random token)
        $randomToken = bin2hex(random_bytes(16));
        $storedFileName = "{$randomToken}.{$safeExt}";
        $targetPath = rtrim($destinationDir, '/\\') . DIRECTORY_SEPARATOR . $storedFileName;

        // 8. Image EXIF Stripping & Re-encoding (Anti-Steganography)
        $isImage = in_array($safeExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
        if ($isImage && $stripExif && extension_loaded('gd')) {
            self::stripExifAndReEncode($tmpPath, $targetPath, $safeExt);
        } else {
            // Secure copy / move to isolated storage
            if (is_uploaded_file($tmpPath)) {
                if (!move_uploaded_file($tmpPath, $targetPath)) {
                    throw new RuntimeException("Failed to move uploaded file to target storage.");
                }
            } else {
                if (!copy($tmpPath, $targetPath)) {
                    throw new RuntimeException("Failed to copy file to target storage.");
                }
            }
        }

        // Enforce restrictive read-only permissions (0644)
        if (DIRECTORY_SEPARATOR !== '\\') {
            @chmod($targetPath, 0644);
        }

        $baseDir = dirname(__DIR__, 2);
        $relativePath = str_replace([$baseDir . '/', $baseDir . '\\'], '', $targetPath);

        return [
            'valid'         => true,
            'original_name' => $originalName,
            'stored_name'   => $storedFileName,
            'stored_path'   => $targetPath,
            'relative_path' => $relativePath,
            'mime_type'     => $trueMime,
            'extension'     => $safeExt,
            'size'          => filesize($targetPath),
            'sha256'        => hash_file('sha256', $targetPath),
        ];
    }

    /**
     * Inspect file name for null bytes, dangerous blacklisted extensions, and double extensions.
     */
    public static function inspectFileName(string $filename, array $allowedExtensions): string
    {
        // Check for null byte injection
        if (str_contains($filename, "\0") || str_contains($filename, '%00')) {
            self::logSecurityViolation('NULL_BYTE_FILENAME', $filename, 'Null byte detected in file name.');
            throw new RuntimeException("Null byte detected in file name.");
        }

        // Strip directory traversal sequences from name
        $cleanName = basename(str_replace(['../', '..\\'], '', $filename));

        // Split into parts by dot
        $parts = explode('.', strtolower($cleanName));
        if (count($parts) < 2) {
            self::logSecurityViolation('NO_EXTENSION', $filename, 'File must have a valid extension.');
            throw new RuntimeException("Uploaded file lacks a valid extension.");
        }

        $finalExtension = end($parts);

        // Check every segment against dangerous executable extensions
        foreach ($parts as $index => $part) {
            if (in_array($part, self::$dangerousExtensions, true)) {
                self::logSecurityViolation(
                    'DANGEROUS_EXTENSION_DETECTED',
                    $filename,
                    "Segment [{$part}] matches dangerous executable blacklist."
                );
                throw new RuntimeException("Forbidden file extension or double extension detected: .{$part}");
            }
        }

        // Verify the final extension is explicitly permitted
        $normalizedAllowed = array_map('strtolower', $allowedExtensions);
        if (!in_array($finalExtension, $normalizedAllowed, true)) {
            self::logSecurityViolation(
                'DISALLOWED_EXTENSION',
                $filename,
                "Extension [{$finalExtension}] is not allowed."
            );
            throw new RuntimeException("File extension .{$finalExtension} is not permitted.");
        }

        return $finalExtension;
    }

    /**
     * Verify true MIME type via magic bytes using finfo_file.
     */
    public static function verifyTrueMimeType(string $filePath, string $extension): string
    {
        if (!function_exists('finfo_open')) {
            throw new RuntimeException("Fileinfo extension is required for MIME verification.");
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new RuntimeException("Failed to initialize Fileinfo.");
        }

        $detectedMime = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        if ($detectedMime === false || !is_string($detectedMime)) {
            self::logSecurityViolation('MIME_DETECTION_FAILED', $filePath, 'Unable to determine MIME type.');
            throw new RuntimeException("Could not verify file MIME type.");
        }

        $detectedMime = strtolower(trim($detectedMime));

        // If extension is mapped in our canonical dictionary, enforce matching
        if (isset(self::$mimeMap[$extension])) {
            $expectedMimes = self::$mimeMap[$extension];
            if (!in_array($detectedMime, $expectedMimes, true)) {
                self::logSecurityViolation(
                    'MIME_MISMATCH',
                    basename($filePath),
                    "Extension .{$extension} claims allowed type but true magic bytes reveal: {$detectedMime}"
                );
                throw new RuntimeException("MIME type mismatch: file claims .{$extension} but detected type is {$detectedMime}.");
            }
        }

        // Specifically block PHP / Shell / Executable MIME types regardless of extension
        $disallowedMimes = [
            'text/x-php',
            'application/x-php',
            'application/x-httpd-php',
            'application/x-httpd-php-source',
            'application/x-executable',
            'application/x-sharedlib',
            'application/x-dosexec',
            'application/x-msdownload',
            'text/x-shellscript',
            'text/x-python',
            'text/x-perl',
        ];

        if (in_array($detectedMime, $disallowedMimes, true)) {
            self::logSecurityViolation('EXECUTABLE_MIME_BLOCKED', basename($filePath), "Executable MIME detected: {$detectedMime}");
            throw new RuntimeException("Forbidden executable MIME type detected: {$detectedMime}.");
        }

        return $detectedMime;
    }

    /**
     * Scan file binary contents for embedded PHP tags, code execution, or web shell signatures.
     */
    public static function scanForWebShellSignatures(string $filePath, string $originalName): void
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return;
        }

        // Read up to first 512KB for deep header/body script scanning
        $chunk = (string)fread($handle, 524288);
        fclose($handle);

        foreach (self::$webShellSignatures as $pattern) {
            if (preg_match($pattern, $chunk, $matches)) {
                self::logSecurityViolation(
                    'WEBSHELL_PAYLOAD_DETECTED',
                    $originalName,
                    "Payload matched signature: " . substr($matches[0], 0, 80)
                );
                throw new RuntimeException("Malicious script signature detected inside uploaded file.");
            }
        }
    }

    /**
     * Strip EXIF/IPTC metadata and re-encode image to eliminate hidden payload injection.
     */
    public static function stripExifAndReEncode(string $sourcePath, string $targetPath, string $extension): void
    {
        $raw = file_get_contents($sourcePath);
        if ($raw === false) {
            throw new RuntimeException("Failed to read image for EXIF stripping.");
        }

        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            self::logSecurityViolation('CORRUPTED_IMAGE', basename($sourcePath), 'Image rasterization failed.');
            throw new RuntimeException("Image is corrupted or contains invalid pixel data.");
        }

        $success = false;
        switch ($extension) {
            case 'jpg':
            case 'jpeg':
                $success = imagejpeg($image, $targetPath, 92);
                break;
            case 'png':
                imagealphablending($image, false);
                imagesavealpha($image, true);
                $success = imagepng($image, $targetPath, 8);
                break;
            case 'gif':
                $success = imagegif($image, $targetPath);
                break;
            case 'webp':
                imagealphablending($image, false);
                imagesavealpha($image, true);
                $success = imagewebp($image, $targetPath, 92);
                break;
            default:
                $success = copy($sourcePath, $targetPath);
                break;
        }

        imagedestroy($image);

        if (!$success) {
            throw new RuntimeException("Failed to re-encode and strip metadata from image.");
        }
    }

    /**
     * Create target storage directory and deploy an ironclad .htaccess blocking script execution.
     */
    public static function ensureStorageFortress(string $directory): void
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $htaccessPath = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccessPath)) {
            $htaccessContent = <<<HTACCESS
# ==============================================================================
# NEXUS FILESHIELD - ISOLATED UPLOADS FORTRESS
# Strict denial of script execution within file upload directory
# ==============================================================================

# Disable script execution engines
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>

# Remove all handler bindings for executable extensions
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .cgi .pl .py .sh .bash
RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .cgi .pl .py .sh .bash

# Force static file delivery only
SetHandler default-handler

# Prevent CGI execution and directory listings
Options -ExecCGI -Indexes
HTACCESS;

            @file_put_contents($htaccessPath, $htaccessContent, LOCK_EX);
            if (DIRECTORY_SEPARATOR !== '\\') {
                @chmod($htaccessPath, 0644);
            }
        }
    }

    /**
     * Normalize upload input from $_FILES or direct file path.
     */
    private static function resolveUploadInfo(array|string $file): array
    {
        if (is_array($file)) {
            if (!isset($file['name'], $file['tmp_name'])) {
                throw new InvalidArgumentException("Invalid upload array structure provided.");
            }

            $errorCode = $file['error'] ?? UPLOAD_ERR_OK;
            if ($errorCode !== UPLOAD_ERR_OK) {
                throw new RuntimeException("Upload error occurred with code: {$errorCode}");
            }

            return [
                'name'     => (string)$file['name'],
                'tmp_name' => (string)$file['tmp_name'],
                'size'     => (int)($file['size'] ?? filesize($file['tmp_name'])),
            ];
        }

        if (is_string($file) && file_exists($file)) {
            return [
                'name'     => basename($file),
                'tmp_name' => $file,
                'size'     => (int)filesize($file),
            ];
        }

        throw new InvalidArgumentException("Provided file does not exist or is invalid.");
    }

    /**
     * Log file upload security violations to storage/logs/security.log.
     */
    private static function logSecurityViolation(string $violation, string $filename, string $details): void
    {
        $logPath = dirname(__DIR__, 2) . '/storage/logs/security.log';
        $logDir = dirname($logPath);

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0700, true);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $entry = sprintf(
            "[%s] [FILESHIELD_BLOCKED] [%s] File: %s | Details: %s | IP: %s\n",
            date('Y-m-d H:i:s'),
            $violation,
            $filename,
            $details,
            $ip
        );

        @file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
    }
}
