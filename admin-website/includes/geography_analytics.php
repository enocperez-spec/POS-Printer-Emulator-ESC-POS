<?php
declare(strict_types=1);

function ensure_geography_analytics_schema(PDO $pdo): void
{
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM installations')->fetchAll() as $column) {
        $columns[(string)$column['Field']] = true;
    }
    if (!isset($columns['country_code'])) {
        $pdo->exec("ALTER TABLE installations ADD COLUMN country_code CHAR(2) NOT NULL DEFAULT 'ZZ' AFTER maintenance_expires_at");
    }
    if (!isset($columns['region_code'])) {
        $pdo->exec("ALTER TABLE installations ADD COLUMN region_code VARCHAR(8) NOT NULL DEFAULT '' AFTER country_code");
    }
    if (!isset($columns['geo_updated_at'])) {
        $pdo->exec('ALTER TABLE installations ADD COLUMN geo_updated_at DATETIME(6) NULL AFTER region_code');
    }

    $indexes = [];
    foreach ($pdo->query('SHOW INDEX FROM installations')->fetchAll() as $index) {
        $indexes[(string)$index['Key_name']] = true;
    }
    if (!isset($indexes['ix_installations_geography'])) {
        $pdo->exec('CREATE INDEX ix_installations_geography ON installations (country_code, region_code)');
    }

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
}

function geography_filter_settings(array $query): array
{
    $days = (int)($query['geo_days'] ?? 30);
    if (!in_array($days, [7, 30, 90, 365, 0], true)) {
        $days = 30;
    }
    $metric = (string)($query['geo_metric'] ?? 'installations');
    if (!in_array($metric, ['installations', 'downloads', 'launches', 'print_jobs'], true)) {
        $metric = 'installations';
    }
    $view = (string)($query['geo_view'] ?? 'world');
    if (!in_array($view, ['world', 'usa'], true)) {
        $view = 'world';
    }
    $license = (string)($query['geo_license'] ?? 'all');
    if (!in_array($license, ['all', 'Trial', 'Lite', 'Pro', 'Enterprise'], true)) {
        $license = 'all';
    }
    $version = trim((string)($query['geo_version'] ?? 'all'));
    if ($version !== 'all' && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version) !== 1) {
        $version = 'all';
    }

    return compact('days', 'metric', 'view', 'license', 'version');
}

function geography_versions(PDO $pdo): array
{
    $values = $pdo->query(
        "SELECT app_version FROM installations
         UNION SELECT app_version FROM download_events_daily
         ORDER BY app_version DESC"
    )->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter(array_map('strval', $values), static fn(string $value): bool => $value !== ''));
}

function geography_dashboard_data(PDO $pdo, array $filters): array
{
    $params = [];
    $conditions = [];
    $metric = $filters['metric'];
    $view = $filters['view'];
    $isDownload = $metric === 'downloads';
    $prefix = $isDownload ? 'd' : 'i';
    $codeExpression = $view === 'usa' ? "NULLIF({$prefix}.region_code, '')" : "NULLIF({$prefix}.country_code, '')";

    if ($view === 'usa') {
        $conditions[] = "{$prefix}.country_code = 'US'";
    }
    if ($filters['days'] > 0) {
        $params['since'] = (new DateTimeImmutable('today', new DateTimeZone('UTC')))
            ->modify('-' . ($filters['days'] - 1) . ' days')
            ->format('Y-m-d');
        $conditions[] = ($isDownload ? 'd.event_date' : ($metric === 'installations' ? 'i.last_seen_at' : 'u.usage_date')) . ' >= :since';
    }
    if ($filters['version'] !== 'all') {
        $conditions[] = "{$prefix}.app_version = :app_version";
        $params['app_version'] = $filters['version'];
    }
    if (!$isDownload && $filters['license'] !== 'all') {
        $conditions[] = 'i.license_mode = :license_mode';
        $params['license_mode'] = $filters['license'];
    }

    $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
    if ($metric === 'downloads') {
        $from = ' FROM download_events_daily d';
        $valueExpression = 'SUM(d.download_starts)';
    } elseif ($metric === 'installations') {
        $from = ' FROM installations i';
        $valueExpression = 'COUNT(*)';
    } else {
        $from = ' FROM daily_usage u INNER JOIN installations i ON i.id = u.installation_id';
        $valueExpression = $metric === 'launches' ? 'SUM(u.launch_count)' : 'SUM(u.print_job_count)';
    }

    $statement = $pdo->prepare(
        "SELECT COALESCE({$codeExpression}, '') AS code, {$valueExpression} AS metric_value" .
        $from . $where . ' GROUP BY code ORDER BY metric_value DESC, code'
    );
    foreach ($params as $name => $value) {
        $statement->bindValue($name, $value);
    }
    $statement->execute();

    $rows = [];
    $unknown = 0;
    foreach ($statement->fetchAll() as $row) {
        $code = strtoupper(trim((string)$row['code']));
        $value = (int)$row['metric_value'];
        $isUnknown = $code === '' || $code === 'ZZ' || ($view === 'usa' && preg_match('/^[A-Z]{2}$/', $code) !== 1);
        if ($isUnknown) {
            $unknown += $value;
            continue;
        }
        $rows[] = ['code' => strtolower($code), 'value' => $value];
    }

    return [
        'rows' => $rows,
        'knownTotal' => array_sum(array_column($rows, 'value')),
        'unknownTotal' => $unknown,
        'generatedAt' => gmdate('c'),
        'metric' => $metric,
        'view' => $view,
    ];
}
