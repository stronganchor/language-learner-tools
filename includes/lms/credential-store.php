<?php
/**
 * Reversible credential storage for LMS integrations.
 *
 * The master key is deliberately configuration-only. It must come from
 * wp-config.php/environment-backed code (or a test filter), never an option or
 * an admin form.
 */

if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_LMS_CREDENTIAL_ENVELOPE_VERSION')) {
    define('LL_TOOLS_LMS_CREDENTIAL_ENVELOPE_VERSION', 1);
}

/**
 * Encode bytes without padding for compact, transport-safe envelopes.
 */
function ll_tools_lms_base64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/**
 * Strictly decode unpadded base64url.
 *
 * @return string|false
 */
function ll_tools_lms_base64url_decode(string $value) {
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
        return false;
    }

    $padding = strlen($value) % 4;
    if ($padding !== 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return base64_decode(strtr($value, '-_', '+/'), true);
}

/**
 * Resolve the configured 32-byte credential key.
 *
 * Supported representations are 32 raw bytes, 64 hexadecimal characters, or
 * a `base64:` prefix followed by a strict base64 encoding of 32 bytes.
 *
 * @return string|WP_Error
 */
function ll_tools_lms_credential_master_key() {
    $configured = defined('LL_TOOLS_LMS_CREDENTIAL_KEY')
        ? constant('LL_TOOLS_LMS_CREDENTIAL_KEY')
        : '';

    /**
     * Test/configuration-code override. Never populate this from the database.
     *
     * @param mixed $configured
     */
    $configured = apply_filters('ll_tools_lms_credential_master_key', $configured);
    if (!is_string($configured) || $configured === '') {
        return new WP_Error(
            'll_tools_lms_credential_key_missing',
            __('The LMS credential encryption key is not configured.', 'll-tools-text-domain')
        );
    }

    $key = $configured;
    if (str_starts_with($configured, 'base64:')) {
        $key = base64_decode(substr($configured, 7), true);
        if ($key === false) {
            $key = '';
        }
    } elseif (strlen($configured) === 64 && ctype_xdigit($configured)) {
        $decoded = hex2bin($configured);
        $key = $decoded === false ? '' : $decoded;
    }

    if (strlen($key) !== 32) {
        return new WP_Error(
            'll_tools_lms_credential_key_invalid',
            __('The LMS credential encryption key must contain exactly 32 bytes.', 'll-tools-text-domain')
        );
    }

    return $key;
}

function ll_tools_lms_credential_store_is_available(): bool {
    $key = ll_tools_lms_credential_master_key();
    if (is_wp_error($key)) {
        return false;
    }

    return function_exists('sodium_crypto_secretbox')
        || (
            function_exists('openssl_encrypt')
            && function_exists('openssl_decrypt')
            && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)
        );
}

/**
 * Derive a context-specific key so sodium secretbox is cryptographically bound
 * to the same record context despite not accepting separate AAD.
 */
function ll_tools_lms_credential_context_key(string $master_key, string $context): string {
    return hash_hkdf(
        'sha256',
        $master_key,
        32,
        'll-tools-lms-credential-envelope-v1',
        hash('sha256', $context, true)
    );
}

/**
 * Return a generic authentication failure without leaking envelope internals.
 */
function ll_tools_lms_credential_tamper_error(): WP_Error {
    return new WP_Error(
        'll_tools_lms_credential_tampered',
        __('The stored integration credential could not be authenticated.', 'll-tools-text-domain')
    );
}

/**
 * Seal a credential payload in a versioned authenticated envelope.
 *
 * @return string|WP_Error
 */
function ll_tools_lms_seal_secret(string $plaintext, string $context) {
    if ($context === '' || strlen($context) > 2048 || strlen($plaintext) > 131072) {
        return new WP_Error(
            'll_tools_lms_credential_input_invalid',
            __('The integration credential payload is invalid.', 'll-tools-text-domain')
        );
    }

    $master_key = ll_tools_lms_credential_master_key();
    if (is_wp_error($master_key)) {
        return $master_key;
    }

    try {
        $context_hash = hash('sha256', $context);
        $key = ll_tools_lms_credential_context_key($master_key, $context);
        $envelope = [
            'v' => LL_TOOLS_LMS_CREDENTIAL_ENVELOPE_VERSION,
            'ctx' => $context_hash,
        ];

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
            $envelope['alg'] = 'sbox';
            $envelope['nonce'] = ll_tools_lms_base64url_encode($nonce);
            $envelope['ct'] = ll_tools_lms_base64url_encode($ciphertext);
        } elseif (
            function_exists('openssl_encrypt')
            && function_exists('openssl_decrypt')
            && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)
        ) {
            $nonce = random_bytes(12);
            $tag = '';
            $aad = 'll-tools-lms|1|' . $context_hash;
            $ciphertext = openssl_encrypt(
                $plaintext,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                $aad,
                16
            );
            if ($ciphertext === false || strlen($tag) !== 16) {
                throw new RuntimeException('Credential encryption failed.');
            }
            $envelope['alg'] = 'a256gcm';
            $envelope['nonce'] = ll_tools_lms_base64url_encode($nonce);
            $envelope['tag'] = ll_tools_lms_base64url_encode($tag);
            $envelope['ct'] = ll_tools_lms_base64url_encode($ciphertext);
        } else {
            return new WP_Error(
                'll_tools_lms_credential_crypto_unavailable',
                __('Authenticated credential encryption is unavailable on this server.', 'll-tools-text-domain')
            );
        }

        $json = wp_json_encode($envelope, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('Credential envelope encoding failed.');
        }

        return 'lltcred1.' . ll_tools_lms_base64url_encode($json);
    } catch (Throwable $error) {
        return new WP_Error(
            'll_tools_lms_credential_encrypt_failed',
            __('The integration credential could not be encrypted.', 'll-tools-text-domain')
        );
    }
}

/**
 * Authenticate and open a versioned credential envelope.
 *
 * @return string|WP_Error
 */
function ll_tools_lms_open_secret(string $sealed, string $context) {
    if (
        $context === ''
        || strlen($context) > 2048
        || strlen($sealed) > 262144
        || !str_starts_with($sealed, 'lltcred1.')
    ) {
        return ll_tools_lms_credential_tamper_error();
    }

    $master_key = ll_tools_lms_credential_master_key();
    if (is_wp_error($master_key)) {
        return $master_key;
    }

    try {
        $decoded = ll_tools_lms_base64url_decode(substr($sealed, 9));
        if ($decoded === false) {
            return ll_tools_lms_credential_tamper_error();
        }

        $envelope = json_decode($decoded, true);
        if (!is_array($envelope)) {
            return ll_tools_lms_credential_tamper_error();
        }

        $version = isset($envelope['v']) ? (int) $envelope['v'] : 0;
        $algorithm = isset($envelope['alg']) && is_string($envelope['alg']) ? $envelope['alg'] : '';
        $stored_context = isset($envelope['ctx']) && is_string($envelope['ctx']) ? $envelope['ctx'] : '';
        $expected_context = hash('sha256', $context);
        if (
            $version !== LL_TOOLS_LMS_CREDENTIAL_ENVELOPE_VERSION
            || strlen($stored_context) !== 64
            || !hash_equals($expected_context, $stored_context)
        ) {
            return ll_tools_lms_credential_tamper_error();
        }

        $nonce = isset($envelope['nonce']) && is_string($envelope['nonce'])
            ? ll_tools_lms_base64url_decode($envelope['nonce'])
            : false;
        $ciphertext = isset($envelope['ct']) && is_string($envelope['ct'])
            ? ll_tools_lms_base64url_decode($envelope['ct'])
            : false;
        if ($nonce === false || $ciphertext === false) {
            return ll_tools_lms_credential_tamper_error();
        }

        $key = ll_tools_lms_credential_context_key($master_key, $context);
        if ($algorithm === 'sbox') {
            if (!function_exists('sodium_crypto_secretbox_open') || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return ll_tools_lms_credential_tamper_error();
            }
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        } elseif ($algorithm === 'a256gcm') {
            $tag = isset($envelope['tag']) && is_string($envelope['tag'])
                ? ll_tools_lms_base64url_decode($envelope['tag'])
                : false;
            if (
                !function_exists('openssl_decrypt')
                || strlen($nonce) !== 12
                || $tag === false
                || strlen($tag) !== 16
            ) {
                return ll_tools_lms_credential_tamper_error();
            }
            $plaintext = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                'll-tools-lms|1|' . $stored_context
            );
        } else {
            return ll_tools_lms_credential_tamper_error();
        }

        if (!is_string($plaintext)) {
            return ll_tools_lms_credential_tamper_error();
        }

        return $plaintext;
    } catch (Throwable $error) {
        return ll_tools_lms_credential_tamper_error();
    }
}
