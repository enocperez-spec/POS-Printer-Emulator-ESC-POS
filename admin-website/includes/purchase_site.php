<?php
declare(strict_types=1);

function purchase_site_config(): array
{
    $path = dirname(__DIR__) . '/private/purchase-site.php';
    $config = is_file($path) ? require $path : null;
    if (!is_array($config) || empty($config['base_url']) || empty($config['admin_token'])) {
        throw new RuntimeException('Purchase-site management is not configured.');
    }
    return $config;
}

function purchase_site_request(string $path, string $method = 'GET', ?array $payload = null): array
{
    $config = purchase_site_config();
    $headers = ['Accept: application/json', 'X-PPE-Admin-Token: ' . $config['admin_token']];
    if (!preg_match('#^/api/[a-z-]+\.php(?:\?[A-Za-z0-9_=&-]+)?$#', $path)) throw new InvalidArgumentException('The purchase-site API path is invalid.');
    $curl = curl_init(rtrim((string)$config['base_url'], '/') . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>15]);
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    }
    $body = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $error = curl_error($curl); curl_close($curl);
    $data = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300 || !is_array($data)) {
        $message = is_array($data) ? (string)($data['error'] ?? '') : '';
        throw new RuntimeException($message !== '' ? $message : 'The Buy website could not be reached. ' . $error);
    }
    return $data;
}
