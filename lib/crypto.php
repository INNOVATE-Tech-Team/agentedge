<?php
// Field-level encryption for sensitive values (SSN, EIN) stored in local_db().
// Uses libsodium secretbox (XSalsa20-Poly1305) with a single server-side key
// from config.php's 'tax_id_encryption_key' — never stored in the database.
//
// Encrypted values are stored as base64(nonce . ciphertext) so they carry
// everything needed to decrypt except the key itself.

function tax_id_key(): string {
    $b64 = cfg()['tax_id_encryption_key'] ?? '';
    if ($b64 === '') {
        throw new \RuntimeException('tax_id_encryption_key is not configured in config.php');
    }
    $key = base64_decode($b64, true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new \RuntimeException('tax_id_encryption_key must be a base64-encoded 32-byte key');
    }
    return $key;
}

// Returns '' for empty input so blank form fields don't produce ciphertext.
function tax_id_encrypt(string $plain): string {
    $plain = trim($plain);
    if ($plain === '') return '';
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, tax_id_key());
    return base64_encode($nonce . $cipher);
}

// Returns null if $encoded is empty or fails to decrypt (wrong/rotated key, corrupt data).
function tax_id_decrypt(string $encoded): ?string {
    $encoded = trim($encoded);
    if ($encoded === '') return null;
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($cipher, $nonce, tax_id_key());
    return $plain === false ? null : $plain;
}

// Last 4 characters for masked display without a full decrypt-and-show.
function tax_id_last4(string $encoded): string {
    $plain = tax_id_decrypt($encoded);
    if ($plain === null || $plain === '') return '';
    return substr($plain, -4);
}
