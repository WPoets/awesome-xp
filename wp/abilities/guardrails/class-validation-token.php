<?php
namespace aw2\abilities\guardrails;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validation Token Manager
 *
 * Implements the validation gate: no write succeeds without a
 * validation token that cryptographically matches the exact code being saved.
 */
class Validation_Token
{
    const TTL_SECONDS = 900; // 15 minutes

    /**
     * Generate a cryptographic token for validated code and context.
     *
     * @param string $code
     * @param string $context 'block' | 'service' | 'app' | 'template'
     * @return string
     */
    public static function generate($code, $context = 'block')
    {
        $code_hash = hash('sha256', trim((string)$code));
        $timestamp = time();
        $salt = defined('NONCE_SALT') ? NONCE_SALT : 'awx_fallback_salt_key';
        
        $signature = hash_hmac('sha256', "{$code_hash}|{$context}|{$timestamp}", $salt);
        $token = "{$code_hash}.{$timestamp}.{$signature}";

        // Also record transient for fast server-side verification and replay protection
        set_transient('awx_vtok_' . $code_hash, [
            'context'   => $context,
            'timestamp' => $timestamp,
            'token'     => $token
        ], self::TTL_SECONDS);

        return $token;
    }

    /**
     * Verify whether a token matches the submitted code and context.
     *
     * @param string $token
     * @param string $code
     * @param string $context
     * @return bool
     */
    public static function verify($token, $code, $context = 'block')
    {
        if (empty($token) || empty($code)) {
            return false;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        list($token_code_hash, $timestamp, $signature) = $parts;
        $timestamp = (int)$timestamp;

        // Check expiration
        if ((time() - $timestamp) > self::TTL_SECONDS || $timestamp > (time() + 60)) {
            return false;
        }

        // Check code hash
        $actual_hash = hash('sha256', trim((string)$code));
        if (!hash_equals($token_code_hash, $actual_hash)) {
            return false;
        }

        // Verify HMAC signature
        $salt = defined('NONCE_SALT') ? NONCE_SALT : 'awx_fallback_salt_key';
        $expected_sig = hash_hmac('sha256', "{$token_code_hash}|{$context}|{$timestamp}", $salt);

        return hash_equals($expected_sig, $signature);
    }
}
