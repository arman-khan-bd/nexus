<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Env;
use RuntimeException;

/**
 * Class Crypto
 *
 * Enterprise cryptographic engine supporting:
 * - Libsodium XChaCha20-Poly1305 / Secretbox authenticated encryption
 * - OpenSSL AES-256-GCM fallback with authentication tags
 * - Constant-time comparisons (hash_equals)
 * - HMAC-SHA256 data integrity
 */
final class Crypto
{
    /**
     * Cached 32-byte binary key.
     */
    private static ?string $key = null;

    /**
     * Get or initialize the validated 256-bit application key.
     */
    public static function getKey(): string
    {
        if (self::$key === null) {
            self::$key = Env::validateAppKey();
        }
        return self::$key;
    }

    /**
     * Encrypt a string using authenticated encryption.
     * Output format: 'v1:sodium:' . base64(nonce . ciphertext)
     *             or 'v1:gcm:' . base64(iv . tag . ciphertext)
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::getKey();

        if (extension_loaded('sodium') && function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
            return 'v1:sodium:' . base64_encode($nonce . $ciphertext);
        }

        // OpenSSL AES-256-GCM fallback
        if (extension_loaded('openssl')) {
            $ivLength = openssl_cipher_iv_length('aes-256-gcm');
            $iv = random_bytes($ivLength);
            $tag = '';
            $ciphertext = openssl_encrypt(
                $plaintext,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                'nexus-auth-v1',
                16
            );

            if ($ciphertext === false) {
                throw new RuntimeException("OpenSSL AES-256-GCM encryption failed.");
            }

            return 'v1:gcm:' . base64_encode($iv . $tag . $ciphertext);
        }

        throw new RuntimeException("Neither Libsodium nor OpenSSL extension is available for secure encryption.");
    }

    /**
     * Decrypt an authenticated ciphertext.
     *
     * @throws RuntimeException If tampering, corruption, or key mismatch is detected.
     */
    public static function decrypt(string $payload): string
    {
        $key = self::getKey();

        if (str_starts_with($payload, 'v1:sodium:')) {
            if (!extension_loaded('sodium')) {
                throw new RuntimeException("Payload was encrypted with Libsodium, but sodium extension is missing.");
            }

            $raw = base64_decode(substr($payload, 10), true);
            if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
                throw new RuntimeException("Invalid Libsodium ciphertext payload structure.");
            }

            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
            if ($decrypted === false) {
                throw new RuntimeException("Ciphertext authentication failed. Data has been tampered with or key is invalid.");
            }

            return $decrypted;
        }

        if (str_starts_with($payload, 'v1:gcm:')) {
            if (!extension_loaded('openssl')) {
                throw new RuntimeException("Payload was encrypted with OpenSSL AES-256-GCM, but openssl is missing.");
            }

            $raw = base64_decode(substr($payload, 7), true);
            $ivLength = openssl_cipher_iv_length('aes-256-gcm');
            $tagLength = 16;

            if ($raw === false || strlen($raw) < ($ivLength + $tagLength)) {
                throw new RuntimeException("Invalid AES-GCM ciphertext payload structure.");
            }

            $iv = substr($raw, 0, $ivLength);
            $tag = substr($raw, $ivLength, $tagLength);
            $ciphertext = substr($raw, $ivLength + $tagLength);

            $decrypted = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                'nexus-auth-v1'
            );

            if ($decrypted === false) {
                throw new RuntimeException("AES-GCM authentication verification failed. Payload is corrupted or tampered.");
            }

            return $decrypted;
        }

        throw new RuntimeException("Unrecognized encryption envelope format.");
    }

    /**
     * Generate an HMAC-SHA256 signature for data integrity.
     */
    public static function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, self::getKey());
    }

    /**
     * Constant-time signature verification.
     */
    public static function verifyHmac(string $data, string $signature): bool
    {
        $expected = self::hmac($data);
        return hash_equals($expected, $signature);
    }
}
