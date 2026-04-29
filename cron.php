<?php
require __DIR__ . '/app/bootstrap.php';
Database::pdo();
$key = (string)($_GET['key'] ?? '');
if (!hash_equals(Settings::cronKey(), $key)) {
    http_response_code(403);
    exit('Chave do cron inválida.');
}
$result = Engine::runDue(10);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
