<?php
require __DIR__ . '/app/bootstrap.php';
Database::pdo();
$slug = trim((string)($_GET['slug'] ?? ''));
$feed = $slug !== '' ? FeedRepository::findBySlug($slug) : null;
if (!$feed) {
    http_response_code(404);
    exit('Feed não encontrado.');
}
if ((int)$feed['require_token'] === 1 && !hash_equals((string)$feed['token'], (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit('Token inválido.');
}
$items = FeedRepository::items((int)$feed['id'], 80);
header('Content-Type: application/rss+xml; charset=UTF-8');
echo RssBuilder::build($feed, $items);
