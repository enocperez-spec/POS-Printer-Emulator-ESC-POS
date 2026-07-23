<?php
declare(strict_types=1);

// Merge this section into private/config.php. Generate a random bearer token,
// store only its SHA-256 hex digest here, and give the plaintext token only to
// the trusted server-side Customer Portal service. Never expose it to browsers.
return [
    'service_api' => [
        'token_hash' => 'REPLACE_WITH_SHA256_HEX_OF_A_RANDOM_32_BYTE_TOKEN',
    ],
    'data_protection' => [
        // Base64 encoding of exactly 32 random bytes. Back this value up in the
        // protected deployment vault; losing it makes encrypted legacy keys
        // unrecoverable. Never reuse the service API token or signing key.
        'activation_key_key' => 'REPLACE_WITH_BASE64_OF_A_RANDOM_32_BYTE_KEY',
    ],
];
