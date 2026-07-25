<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$website = $root . '/website';
$failures = [];

$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$catalog = json_decode((string)file_get_contents($website . '/license-catalog.json'), true);
$release = json_decode((string)file_get_contents($website . '/release.json'), true);
$version = (string)($catalog['sourceOfTruth']['applicationVersion'] ?? '');
$expect($version !== '', 'License catalog must declare sourceOfTruth.applicationVersion.');
$expect($version === (string)($release['currentVersion'] ?? ''), 'Catalog and website release versions must match.');

$productInfo = (string)file_get_contents($root . '/src/ReceiptEmulator.App/ProductInfo.cs');
$expect(str_contains($productInfo, '"' . $version . '"'), 'ProductInfo.cs must match the public catalog version.');

$features = [];
foreach (($catalog['features'] ?? []) as $feature) {
    $features[(string)$feature['name']] = $feature;
}
$expect(($catalog['licenses']['Lite']['listenerLimit'] ?? null) === 1, 'Lite listener limit must be 1.');
$expect(($catalog['licenses']['Pro']['listenerLimit'] ?? null) === 2, 'Pro listener limit must be 2.');
$expect(($catalog['licenses']['Enterprise']['listenerLimit'] ?? null) === 15, 'Enterprise listener limit must be 15.');
$expect(($features['Copy Receipt as Image and clipboard image support']['Trial'] ?? '') === 'Unavailable', 'Copy Receipt as Image must remain unavailable in Trial.');
$expect(($features['Copy Receipt as Image and clipboard image support']['Lite'] ?? '') === 'Included', 'Copy Receipt as Image must be included in Lite.');
$expect(($features['Standard Development Diagnostics Report']['Enterprise'] ?? '') === 'Enterprise only', 'Standard Development Diagnostics Report must be Enterprise.');
$expect(($features['Standard Development Diagnostics Report']['Pro'] ?? '') === 'Unavailable', 'Standard Development Diagnostics Report must remain unavailable in Pro.');
$expect(($features['Advanced Diagnostics Package']['Enterprise'] ?? '') === 'Enterprise only', 'Advanced Diagnostics Package must be Enterprise.');

foreach (['buy-website', 'customer-portal'] as $surface) {
    $copy = $root . '/' . $surface . '/assets/license-catalog.json';
    $expect(is_file($copy), $surface . ' must contain the synchronized license catalog.');
    $expect(is_file($copy) && hash_file('sha256', $copy) === hash_file('sha256', $website . '/license-catalog.json'), $surface . ' catalog copy must match the canonical catalog.');
}

$customerPages = [
    'index.html', 'features.html', 'documentation.html', 'pricing.html', 'faq.html',
    'pos-printer-emulator-download.html', 'user-portal-guide.html',
    'application-maintenance-support.html'
];
foreach ($customerPages as $page) {
    $html = (string)file_get_contents($website . '/' . $page);
    $expect((bool)preg_match('/<title>[^<]+<\/title>/i', $html), $page . ' must have a title.');
    $expect((bool)preg_match('/<meta\s+name="description"\s+content="[^"]+"/i', $html), $page . ' must have a meta description.');
    $expect((bool)preg_match('/<link\s+rel="canonical"\s+href="https:\/\/www\.posprinteremulator\.com\//i', $html), $page . ' must have a canonical URL.');
    $expect(!preg_match('/\bFull (License|Version)\b/i', $html), $page . ' must not use obsolete Full License/Full Version wording.');
}

$comparisonPages = ['index.html', 'features.html', 'documentation.html', 'pricing.html'];
foreach ($comparisonPages as $page) {
    $html = (string)file_get_contents($website . '/' . $page);
    $expect(str_contains($html, 'data-license-comparison'), $page . ' must render the canonical license comparison.');
}

$pricing = (string)file_get_contents($website . '/pricing.html');
$expect(
    str_contains($pricing, 'https://userportal.posprinteremulator.com/index.php?return=plans'),
    'Pricing purchase options must require Customer Portal authentication and preserve the Plans destination.'
);
$expect(
    !str_contains($pricing, 'href="https://buy.posprinteremulator.com/"'),
    'Pricing must not send View Purchase Options directly to the public Buy page.'
);

$mainGuide = (string)file_get_contents($website . '/how-to-use-pos-printer-emulator-main-page.html');
$expect((bool)preg_match('/built-in Test Receipts are\s+unlimited/i', $mainGuide), 'Main guide must state that built-in Test Receipts are unlimited.');
$expect((bool)preg_match('/do not count toward the five\s+external POS print jobs/i', $mainGuide), 'Main guide must state that Test Receipts do not use the Trial daily allowance.');
$expect((bool)preg_match('/lower-left\s+corner throughout Settings/i', $mainGuide), 'Main guide must document the Settings version location.');

$supportGuide = (string)file_get_contents($website . '/how-to-submit-a-support-request.html');
$expect(str_contains($supportGuide, 'Other Issue (general support)'), 'Support guide must identify the general-support request category.');

$sitemap = (string)file_get_contents($website . '/sitemap.xml');
foreach (['features', 'user-portal-guide', 'documentation', 'pricing', 'faq'] as $slug) {
    $expect(str_contains($sitemap, 'https://www.posprinteremulator.com/' . $slug), 'Sitemap must include /' . $slug . '.');
}

$allPublicHtml = glob($website . '/*.html') ?: [];
foreach ($allPublicHtml as $file) {
    $html = (string)file_get_contents($file);
    $expect(!preg_match('/five (emulated print|test) jobs per day/i', $html), basename($file) . ' contains obsolete Trial wording.');
    $expect(!preg_match('/license levels? (are available )?in v0\.3\.(25|26)/i', $html), basename($file) . ' contains an obsolete marketing release reference.');

    preg_match_all('/\b(?:href|src)="([^"]+)"/i', $html, $matches);
    foreach ($matches[1] as $reference) {
        $path = (string)parse_url(html_entity_decode($reference), PHP_URL_PATH);
        if ($path === '' || $path === '/' || str_starts_with($path, '#') ||
            preg_match('#^(?:https?:|mailto:|tel:|data:)#i', $reference)) {
            continue;
        }

        $path = ltrim($path, '/');
        $candidates = [
            $website . '/' . $path,
            $website . '/' . $path . '.html',
            $website . '/' . $path . '.php',
            $website . '/' . $path . '/index.html',
            $website . '/' . $path . '/index.php',
        ];
        $expect(
            array_filter($candidates, 'is_file') !== [],
            basename($file) . ' references missing local resource ' . $reference . '.'
        );
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Website content checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Website content checks passed for release {$version}.\n";
