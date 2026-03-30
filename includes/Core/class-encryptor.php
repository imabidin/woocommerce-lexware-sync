<?php
/**
 * Encryptor - Secure API Token Encryption
 *
 * Encrypts sensitive data (API tokens) using AES-256-GCM.
 * Uses WordPress SECURE_AUTH_KEY as encryption key.
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Core;

class Encryptor {

    /**
     * Encryption method
     *
     * @since 1.0.0
     */
    const METHOD = 'aes-256-gcm';

    /**
     * Encrypt a string
     *
     * Uses AES-256-GCM with random IV for each encryption.
     * Returns base64-encoded IV + ciphertext + authentication tag.
     *
     * @since 1.0.0
     * @param string $data Data to encrypt
     * @return string Base64-encoded encrypted data, empty string on failure
     *
     * @example $encrypted = Encryptor::encrypt('my-api-token');
     */
    public static function encrypt($data) {
        if (empty($data)) {
            return '';
        }

        try {
            $key = self::get_key();
            $iv_length = openssl_cipher_iv_length(self::METHOD);
            $iv = openssl_random_pseudo_bytes($iv_length);

            $tag = '';
            $encrypted = openssl_encrypt(
                $data,
                self::METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($encrypted === false) {
                throw new \Exception('Verschlüsselung fehlgeschlagen');
            }

            // IV + verschlüsselte Daten + Tag kombinieren
            return base64_encode($iv . $encrypted . $tag);

        } catch (Exception $e) {
            \WC_Lexware_MVP_Logger::error('Verschlüsselungsfehler', array(
                'error_message' => $e->getMessage(),
                'method' => self::METHOD
            ));
            return '';
        }
    }

    /**
     * Decrypt a string
     *
     * Decodes base64 and extracts IV, ciphertext, and authentication tag.
     * Verifies tag authenticity before decryption.
     *
     * @since 1.0.0
     * @param string $data Base64-encoded encrypted data
     * @return string Decrypted data, empty string on failure
     *
     * @example $token = Encryptor::decrypt($encrypted_token);
     */
    public static function decrypt($data) {
        if (empty($data)) {
            return '';
        }

        try {
            $key = self::get_key();
            $decoded = base64_decode($data);

            if ($decoded === false) {
                throw new \Exception('Base64-Dekodierung fehlgeschlagen');
            }

            $iv_length = openssl_cipher_iv_length(self::METHOD);
            $tag_length = 16; // GCM Tag ist immer 16 Bytes

            $iv = substr($decoded, 0, $iv_length);
            $tag = substr($decoded, -$tag_length);
            $encrypted = substr($decoded, $iv_length, -$tag_length);

            $decrypted = openssl_decrypt(
                $encrypted,
                self::METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($decrypted === false) {
                throw new \Exception('Entschlüsselung fehlgeschlagen');
            }

            return $decrypted;

        } catch (Exception $e) {
            \WC_Lexware_MVP_Logger::error('Entschlüsselungsfehler', array(
                'error_message' => $e->getMessage(),
                'method' => self::METHOD
            ));
            return '';
        }
    }

    /**
     * Get encryption key from WordPress
     *
     * Derives 256-bit key from SECURE_AUTH_KEY constant.
     * Throws exception if constant is not defined.
     *
     * @since 1.0.0
     * @return string 32-byte encryption key
     * @throws \Exception If SECURE_AUTH_KEY is not defined
     */
    private static function get_key() {
        if (!defined('SECURE_AUTH_KEY') || empty(SECURE_AUTH_KEY)) {
            throw new \Exception('SECURE_AUTH_KEY ist nicht in wp-config.php definiert');
        }

        return hash('sha256', SECURE_AUTH_KEY, true);
    }
}
