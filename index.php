<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/../src/api.php';
    exit;
}
if ($path === '/reset-password') {
    require __DIR__ . '/views/reset_password.php';
    exit;
}
if ($path === '/owner-setup') {
    require __DIR__ . '/views/owner_setup.php';
    exit;
}
if ($path === '/payment/return') {
    require __DIR__ . '/../src/payment_return.php';
    exit;
}
if ($path === '/pesapal/ipn') {
    require __DIR__ . '/../src/pesapal_ipn.php';
    exit;
}
if ($path === '/cron/expire-adverts') {
    require __DIR__ . '/../src/expire.php';
    exit;
}
if ($path === '/health') {
    json_response(['ok' => true, 'app' => APP_NAME]);
}
if ($path === '/ready') {
    try { supabase_request('GET','/rest/v1/categories?select=id&limit=1',null,true); json_response(['ok'=>true,'database_api'=>true]); }
    catch (Throwable $e) { error_log('[readiness] '.$e->getMessage()); json_response(['ok'=>false,'database_api'=>false],503); }
}
require __DIR__ . '/views/app.php';
