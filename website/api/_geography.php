<?php
declare(strict_types=1);

const UNKNOWN_COUNTRY_CODE = 'ZZ';

function ensure_geography_storage(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "ALTER TABLE installations
            ADD COLUMN IF NOT EXISTS country_code CHAR(2) NOT NULL DEFAULT 'ZZ' AFTER maintenance_expires_at,
            ADD COLUMN IF NOT EXISTS region_code VARCHAR(8) NOT NULL DEFAULT '' AFTER country_code,
            ADD COLUMN IF NOT EXISTS geo_updated_at DATETIME(6) NULL AFTER region_code"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS ix_installations_geography ON installations (country_code, region_code)');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS download_events_daily (
            event_date DATE NOT NULL,
            country_code CHAR(2) NOT NULL DEFAULT 'ZZ',
            region_code VARCHAR(8) NOT NULL DEFAULT '',
            app_version VARCHAR(32) NOT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'other',
            download_starts BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (event_date, country_code, region_code, app_version, source),
            KEY ix_download_events_geography (country_code, region_code, event_date),
            KEY ix_download_events_version (app_version, event_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $ready = true;
}

function normalize_country_code(mixed $value): string
{
    $code = strtoupper(trim((string)$value));
    return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : UNKNOWN_COUNTRY_CODE;
}

function normalize_region_code(mixed $value, string $countryCode): string
{
    if ($countryCode !== 'US') {
        return '';
    }

    $code = strtoupper(trim((string)$value));
    return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : '';
}

function request_public_ip(): ?string
{
    $candidate = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return null;
    }

    return $candidate;
}

function request_geography(): array
{
    $serverCountry = normalize_country_code($_SERVER['GEOIP_COUNTRY_CODE'] ?? '');
    if ($serverCountry !== UNKNOWN_COUNTRY_CODE) {
        return [
            'country_code' => $serverCountry,
            'region_code' => normalize_region_code($_SERVER['GEOIP_REGION'] ?? '', $serverCountry),
            'source' => 'server',
        ];
    }

    $ipAddress = request_public_ip();
    if ($ipAddress === null) {
        return unknown_geography();
    }

    $configuration = private_config()['geolocation'] ?? [];
    if (is_array($configuration) && array_key_exists('enabled', $configuration) && $configuration['enabled'] === false) {
        return unknown_geography();
    }

    $template = is_array($configuration) ? trim((string)($configuration['endpoint_template'] ?? '')) : '';
    if ($template === '') {
        $template = 'https://ipwho.is/%s?fields=success,country_code,region_code';
    }
    if (substr_count($template, '%s') !== 1 || !str_starts_with($template, 'https://')) {
        return unknown_geography();
    }

    $response = geography_http_get(sprintf($template, rawurlencode($ipAddress)));
    if ($response === null) {
        return unknown_geography();
    }

    try {
        $decoded = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return unknown_geography();
    }
    if (!is_array($decoded) || ($decoded['success'] ?? true) === false || ($decoded['error'] ?? false) === true) {
        return unknown_geography();
    }

    $countryCode = normalize_country_code($decoded['country_code'] ?? $decoded['country'] ?? '');
    if ($countryCode === UNKNOWN_COUNTRY_CODE) {
        return unknown_geography();
    }

    return [
        'country_code' => $countryCode,
        'region_code' => normalize_region_code($decoded['region_code'] ?? '', $countryCode),
        'source' => 'ip-geolocation',
    ];
}

function geography_http_get(string $url): ?string
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_USERAGENT => 'POSPrinterEmulator-Geography/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return is_string($response) && $status >= 200 && $status < 300 && strlen($response) <= 32768
            ? $response
            : null;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 3,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: POSPrinterEmulator-Geography/1.0\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $response = @file_get_contents($url, false, $context, 0, 32769);
    return is_string($response) && strlen($response) <= 32768 ? $response : null;
}

function unknown_geography(): array
{
    return ['country_code' => UNKNOWN_COUNTRY_CODE, 'region_code' => '', 'source' => 'unknown'];
}

function should_refresh_geography(?string $countryCode, mixed $updatedAt): bool
{
    if ($updatedAt === null || trim((string)$updatedAt) === '') {
        return true;
    }

    $timestamp = strtotime((string)$updatedAt . ' UTC');
    if ($timestamp === false) {
        return true;
    }

    $refreshAfter = normalize_country_code($countryCode ?? '') === UNKNOWN_COUNTRY_CODE ? 86400 : 30 * 86400;
    return $timestamp < time() - $refreshAfter;
}
