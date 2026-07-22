<?php
declare(strict_types=1);

require __DIR__ . '/api/_bootstrap.php';
require __DIR__ . '/api/_geography.php';

const DOWNLOAD_SOURCES = [
    'homepage',
    'download-page',
    'pricing',
    'faq',
    'documentation',
    'guide',
    'privacy',
    'other',
];

function current_release_version(): string
{
    $manifest = json_decode(file_get_contents(__DIR__ . '/release.json') ?: '{}', true);
    $version = is_array($manifest) ? trim((string)($manifest['currentVersion'] ?? '')) : '';
    if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version) !== 1) {
        throw new RuntimeException('The current release is unavailable.');
    }
    return $version;
}

function is_probable_download_bot(): bool
{
    $userAgent = strtolower(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
    return $userAgent === '' || preg_match('/bot|crawler|spider|preview|scanner|headless|monitor/', $userAgent) === 1;
}

function record_download_start(string $version, string $source): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || is_probable_download_bot()) {
        return;
    }

    $geography = request_geography();
    $statement = database()->prepare(
        'INSERT INTO download_events_daily
            (event_date, country_code, region_code, app_version, source, download_starts)
         VALUES (UTC_DATE(), :country_code, :region_code, :app_version, :source, 1)
         ON DUPLICATE KEY UPDATE download_starts = download_starts + 1'
    );
    $statement->execute([
        'country_code' => $geography['country_code'],
        'region_code' => $geography['region_code'],
        'app_version' => $version,
        'source' => $source,
    ]);
}

try {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        exit;
    }

    $version = current_release_version();
    $source = strtolower(trim((string)($_GET['source'] ?? 'other')));
    if (!in_array($source, DOWNLOAD_SOURCES, true)) {
        $source = 'other';
    }

    try {
        record_download_start($version, $source);
    } catch (Throwable $exception) {
        error_log('POS Printer Emulator download geography could not be recorded.');
    }

    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('Location: downloads/POSPrinterEmulatorSetup-' . $version . '-win-x64.exe', true, 302);
    exit;
} catch (Throwable $exception) {
    error_log('POS Printer Emulator download redirect failed: ' . $exception->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'The current installer is temporarily unavailable. Please try again later.';
}
