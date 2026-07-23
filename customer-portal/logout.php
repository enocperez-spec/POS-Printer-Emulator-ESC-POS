<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
portal_require_csrf();
portal_logout();
