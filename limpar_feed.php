<?php
require __DIR__ . '/app/bootstrap.php';

$slug = trim((string)($_GET['slug'] ?? ''));

if ($slug === '') {
    exit('Informe o slug.');
}

$feed = FeedRepository::findBySlug($slug);

if (!$feed) {
    exit('Feed não encontrado.');
}

$stmt = Database::pdo()->prepare('DELETE FROM items WHERE feed_id = ?');
$stmt->execute([(int)$feed['id']]);

echo 'Itens apagados do feed: ' . htmlspecialchars($feed['name']);