<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/auth.php';
require dirname(__DIR__, 2) . '/includes/communications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

function template_preview_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(static function (Throwable $exception): never {
    error_log('Template preview failed: ' . get_class($exception));
    template_preview_response(500, [
        'error' => 'The email preview service could not complete the request.',
        'detail' => communication_sanitize_provider_error($exception->getMessage()),
    ]);
});

if (!communication_service_authorized()) {
    require_authentication();
    require_admin_capability('communications.read');
}

function template_preview_get(string $url, string $apiKey): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'api-key: ' . $apiKey],
    ]);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if (!is_string($body)) {
        throw new RuntimeException(
            communication_sanitize_provider_error($error !== '' ? $error : 'Provider connection failed.')
        );
    }
    $decoded = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        $message = is_array($decoded) ? (string)($decoded['message'] ?? '') : '';
        throw new RuntimeException(
            communication_sanitize_provider_error($message !== '' ? $message : 'Provider returned HTTP ' . $status . '.')
        );
    }
    return $decoded;
}

function template_preview_samples(string $templateKey): array
{
    $samples = [
        'customer_name' => 'Alex Morgan',
        'first_name' => 'Alex',
        'license_tier' => 'Pro',
        'tier' => 'Pro',
        'application_version' => 'v0.3.47',
        'app_version' => 'v0.3.47',
        'installed_version' => 'v0.3.44',
        'latest_version' => 'v0.3.47',
        'version' => 'v0.3.47',
        'versions_behind' => 2,
        'maintenance_end' => 'December 31, 2026',
        'maintenance_expires_at' => 'December 31, 2026',
        'days_remaining' => 30,
        'renewal_url' => 'https://userportal.posprinteremulator.com/',
        'portal_url' => 'https://userportal.posprinteremulator.com/',
        'download_url' => 'https://www.posprinteremulator.com/pos-printer-emulator-download',
        'support_reference' => 'SUP-TEST00000000',
        'reference' => 'SUP-TEST00000000',
        'support_url' => 'https://userportal.posprinteremulator.com/',
        'issue_url' => 'https://userportal.posprinteremulator.com/',
        'verification_url' => 'https://userportal.posprinteremulator.com/',
        'reset_url' => 'https://userportal.posprinteremulator.com/',
        'setup_url' => 'https://www.posprinteremulator.com/documentation?license=pro',
        'troubleshooting_url' => 'https://www.posprinteremulator.com/esc-pos-troubleshooting',
        'contact_support_url' => 'https://userportal.posprinteremulator.com/',
        'feature_summary' => communication_tier_features('Pro'),
        'event_label' => 'Purchase',
        'preview_text' => 'Setup guidance and support for POS Printer Emulator.',
        'release_summary' => 'Improved receipt rendering and compatibility',
        'release_title' => 'Improved receipt rendering',
        'release_url' => 'https://www.posprinteremulator.com/documentation',
        'amount' => '49.99',
        'currency' => 'USD',
        'order_reference' => 'PPE-TEST-1001',
        'product' => 'POS Printer Emulator',
        'activation_key' => 'Available securely in the Customer Portal',
        'license_id' => 'PPE-TEST-LICENSE',
        'maintenance_token' => 'Available securely in the Customer Portal',
        'request_type' => 'Technical support',
        'subject' => 'Receipt rendering question',
        'documentation_url' => 'https://www.posprinteremulator.com/documentation',
        'help_center_url' => 'https://www.posprinteremulator.com/documentation',
        'support_request_url' => 'https://www.posprinteremulator.com/how-to-submit-a-support-request',
        'no_reply_notice' => 'Please do not reply to this email. This inbox is not monitored.',
        'faq_url' => 'https://www.posprinteremulator.com/faq',
        'first_receipt_url' => 'https://www.posprinteremulator.com/documentation',
        'pricing_url' => 'https://www.posprinteremulator.com/pricing',
        'printer_port_url' => 'https://www.posprinteremulator.com/how-to-create-a-windows-tcp-ip-printer-port',
        'headline' => 'Upgrade your POS testing workflow',
        'offer_label' => 'View upgrade options',
        'offer_summary' => 'Explore additional testing and troubleshooting features.',
        'offer_terms' => 'Offer terms apply.',
        'offer_url' => 'https://buy.posprinteremulator.com/',
        'expires_minutes' => 30,
        'unsubscribe' => 'https://userportal.posprinteremulator.com/',
        'update_profile' => 'https://userportal.posprinteremulator.com/',
    ];
    return array_replace($samples, communication_test_parameters($templateKey, 'Alex Morgan'));
}

function template_preview_populate(string $content, array $samples, array &$missing): string
{
    return preg_replace_callback(
        '/{{\s*(?:params\.)?([a-zA-Z0-9_]+)\s*}}/',
        static function (array $match) use ($samples, &$missing): string {
            $key = strtolower((string)$match[1]);
            if (!array_key_exists($key, $samples)) {
                $missing[$key] = true;
                return '[Missing sample: ' . $key . ']';
            }
            return htmlspecialchars((string)$samples[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        },
        $content
    ) ?? $content;
}

function template_preview_valid_url(string $url): bool
{
    $parts = parse_url(html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return is_array($parts)
        && strtolower((string)($parts['scheme'] ?? '')) === 'https'
        && in_array(strtolower((string)($parts['host'] ?? '')), [
            'posprinteremulator.com',
            'www.posprinteremulator.com',
            'buy.posprinteremulator.com',
            'userportal.posprinteremulator.com',
            'github.com',
        ], true)
        && !isset($parts['user'])
        && !isset($parts['pass']);
}

function sanitize_brevo_preview_html(string $html, array &$invalidLinks, array &$links): string
{
    if ($html === '') return '';
    if (!class_exists(DOMDocument::class)) {
        return '<div style="font-family:Arial,sans-serif;padding:24px">' .
            nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) .
            '</div>';
    }

    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    foreach (['script','iframe','object','embed','form','input','button','textarea','select','video','audio','meta','base','link'] as $tag) {
        $nodes = [];
        foreach ($document->getElementsByTagName($tag) as $node) $nodes[] = $node;
        foreach ($nodes as $node) $node->parentNode?->removeChild($node);
    }

    foreach ($document->getElementsByTagName('*') as $node) {
        $remove = [];
        foreach ($node->attributes ?? [] as $attribute) {
            $name = strtolower($attribute->name);
            $value = trim($attribute->value);
            if (str_starts_with($name, 'on') || $name === 'srcdoc') {
                $remove[] = $attribute->name;
            } elseif ($name === 'href') {
                if (!template_preview_valid_url($value)) {
                    $invalidLinks[] = $value;
                    $node->setAttribute('href', '#invalid-preview-link');
                } else {
                    $links[] = $value;
                    $node->setAttribute('target', '_blank');
                    $node->setAttribute('rel', 'noopener noreferrer');
                }
            } elseif ($name === 'src' && !str_starts_with($value, 'data:image/')) {
                if (!template_preview_valid_url($value)) $remove[] = $attribute->name;
            }
        }
        foreach ($remove as $attributeName) $node->removeAttribute($attributeName);
    }

    $safe = '';
    foreach ($document->getElementsByTagName('style') as $style) $safe .= $document->saveHTML($style);
    $body = $document->getElementsByTagName('body')->item(0);
    if ($body !== null) {
        foreach ($body->childNodes as $child) $safe .= $document->saveHTML($child);
    }
    return $safe;
}

$templateId = filter_input(INPUT_GET, 'template_id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($templateId === false || $templateId === null) {
    template_preview_response(400, ['error' => 'A valid mapped Brevo template ID is required.']);
}

$pdo = database();
ensure_communication_schema($pdo);
$mapped = $pdo->prepare(
    'SELECT template_key,display_name FROM communication_templates WHERE brevo_template_id=:template_id LIMIT 1'
);
$mapped->execute(['template_id' => $templateId]);
$template = $mapped->fetch();
if (!is_array($template)) {
    template_preview_response(404, ['error' => 'The template is not mapped in the approved registry. Save the mapping while disabled, then preview it before activation.']);
}

$config = communication_config();
$apiKey = trim((string)($config['brevo_api_key'] ?? ''));
$apiBase = rtrim((string)($config['brevo_api_base'] ?? 'https://api.brevo.com/v3'), '/');
if ($apiKey === '' || !function_exists('curl_init')) {
    template_preview_response(503, ['error' => 'Brevo preview is not configured on this server.']);
}

try {
    $provider = template_preview_get($apiBase . '/smtp/templates/' . $templateId, $apiKey);
} catch (Throwable $exception) {
    template_preview_response(502, [
        'error' => 'The email preview could not be generated.',
        'detail' => communication_sanitize_provider_error($exception->getMessage()),
    ]);
}

$templateKey = (string)$template['template_key'];
$displayName = (string)$template['display_name'];
$samples = array_replace(
    template_preview_samples($templateKey),
    communication_global_parameters($pdo)
);
$missing = [];
$sourceHtml = (string)($provider['htmlContent'] ?? '');
$subjectSource = (string)($provider['subject'] ?? '');
$previewTextSource = (string)($provider['previewText'] ?? $samples['preview_text']);
$populatedHtml = template_preview_populate($sourceHtml, $samples, $missing);
$subject = strip_tags(template_preview_populate($subjectSource, $samples, $missing));
$previewText = strip_tags(template_preview_populate($previewTextSource, $samples, $missing));
$invalidLinks = [];
$links = [];
$html = sanitize_brevo_preview_html($populatedHtml, $invalidLinks, $links);

$sender = is_array($provider['sender'] ?? null) ? $provider['sender'] : [];
$senderName = trim((string)($sender['name'] ?? $config['sender_name'] ?? 'POS Printer Emulator'));
$senderEmail = trim((string)($sender['email'] ?? $config['sender_email'] ?? ''));
$warnings = [];
foreach (array_keys($missing) as $key) $warnings[] = 'Missing sample data for placeholder: ' . $key;
foreach (array_unique($invalidLinks) as $url) {
    $warnings[] = 'Invalid or unapproved link: ' . crm_text_slice($url, 0, 120);
}
if ($sourceHtml === '' || $html === '') $warnings[] = 'The provider template does not contain previewable HTML.';
if ($subject === '') $warnings[] = 'The provider template does not have a subject line.';
if ($senderName === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
    $warnings[] = 'The sender name or email address is missing or invalid.';
}
$warnings = array_values(array_unique(array_merge(
    $warnings,
    communication_template_language_warnings(
        $subject,
        $populatedHtml,
        (bool)($config['inbox_monitored'] ?? false),
        $sourceHtml
    )
)));
$valid = count($warnings) === 0;

$record = $pdo->prepare(
    'UPDATE communication_templates
     SET preview_brevo_template_id=:template_id,preview_verified_at=UTC_TIMESTAMP(6),
         preview_warnings_json=:warnings
     WHERE template_key=:template_key AND brevo_template_id=:mapped_template_id'
);
$record->execute([
    'template_id' => $templateId,
    'mapped_template_id' => $templateId,
    'warnings' => json_encode($warnings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    'template_key' => $templateKey,
]);

template_preview_response(200, [
    'template_id' => $templateId,
    'name' => $displayName,
    'valid' => $valid,
    'subject' => $subject,
    'preview_text' => $previewText,
    'sender' => ['name' => $senderName, 'email' => $senderEmail],
    'recipient' => ['name' => 'Alex Morgan', 'email' => 'alex.morgan@example.com'],
    'warnings' => $warnings,
    'links' => array_values(array_unique($links)),
    'html' => $html,
]);
