<?php

declare(strict_types=1);

// Copy alongside the selected portal's built index.html. Laravel stays private.
$allowedHosts = [
    'admin-test.dananir-iq.com',
    'agents-test.dananir-iq.com',
    'pos-test.dananir-iq.com',
];
$host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '', 2)[0]);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$isApi = is_string($path) && str_starts_with($path, '/api/v1/');
$isCsrf = $path === '/sanctum/csrf-cookie';

if (!in_array($host, $allowedHosts, true) || (!$isApi && !$isCsrf)) {
    http_response_code(404);
    exit;
}

// Never infer identity or permissions from headers injected by a browser.
unset($_SERVER['HTTP_X_MASAL_PORTAL']);
$backendEntry = '/home/dananiriq/masal-staging/backend/current/public/index.php';
if (!is_file($backendEntry)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'الخدمة غير متاحة مؤقتًا.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require $backendEntry;
