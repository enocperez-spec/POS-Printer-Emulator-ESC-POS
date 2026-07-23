<?php
declare(strict_types=1);

function activation_key_data_protection_key(): ?string
{
    if (!function_exists('private_config')) return null;
    $encoded = trim((string)(private_config()['data_protection']['activation_key_key'] ?? ''));
    if ($encoded === '') return null;
    $key = base64_decode($encoded, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('The activation-key data-protection key is invalid.');
    }
    return $key;
}

function protect_activation_key(string $activationKey): array
{
    $key = activation_key_data_protection_key();
    if ($key === null) {
        return ['plaintext' => $activationKey, 'ciphertext' => null, 'nonce' => null, 'tag' => null];
    }
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('The server cannot protect activation keys because OpenSSL is unavailable.');
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($activationKey, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'ppe-activation-key-v1', 16);
    if ($ciphertext === false || strlen($tag) !== 16) throw new RuntimeException('The activation key could not be protected.');
    return [
        'plaintext' => '',
        'ciphertext' => $ciphertext,
        'nonce' => $nonce,
        'tag' => $tag,
    ];
}

function crm_activation_key_ending_compat(string $activationKey): string
{
    $compact = preg_replace('/[^A-Za-z0-9]/', '', $activationKey) ?? '';
    return $compact === '' ? '' : substr($compact, -4);
}

function reveal_activation_key(array $record): string
{
    $ciphertext = $record['activation_key_ciphertext'] ?? null;
    $nonce = $record['activation_key_nonce'] ?? null;
    $tag = $record['activation_key_tag'] ?? null;
    if (is_string($ciphertext) && $ciphertext !== '') {
        $key = activation_key_data_protection_key();
        if (!function_exists('openssl_decrypt') || $key === null || !is_string($nonce) || strlen($nonce) !== 12 || !is_string($tag) || strlen($tag) !== 16) {
            throw new RuntimeException('Protected activation-key material is unavailable.');
        }
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'ppe-activation-key-v1');
        if ($plaintext === false) throw new RuntimeException('Protected activation-key material failed integrity verification.');
        return $plaintext;
    }
    return (string)($record['activation_key'] ?? '');
}
