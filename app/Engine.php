<?php
declare(strict_types=1);

final class Engine
{
    public static function runDue(int $limit = 5): array
    {
        $lock = STORAGE_PATH . '/cron.lock';
        $fp = fopen($lock, 'c');

        if (!$fp) {
            return [
                'ok' => false,
                'message' => 'Não foi possível criar o arquivo de trava do cron.',
                'feeds' => []
            ];
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);

            return [
                'ok' => false,
                'message' => 'Já existe uma execução em andamento.',
                'feeds' => []
            ];
        }

        $results = [];

        try {
            $feeds = FeedRepository::due($limit);

            foreach ($feeds as $feed) {
                $results[] = self::runFeed($feed);
            }
        } catch (Throwable $e) {
            self::log(null, 'erro', 'Erro geral no cron: ' . $e->getMessage());
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        return [
            'ok' => true,
            'message' => 'Execução finalizada.',
            'feeds' => $results
        ];
    }

    public static function runOne(int $id): array
    {
        $feed = FeedRepository::find($id);

        if (!$feed) {
            throw new RuntimeException('Feed não encontrado.');
        }

        return self::runFeed($feed);
    }

    public static function preview(array $data): array
    {
        $data['max_items'] = min(10, max(1, (int)($data['max_items'] ?? 10)));

        return Extractor::extractFromList($data, true);
    }

    private static function runFeed(array $feed): array
    {
        $pdo = Database::pdo();
        $count = 0;
        $translated = 0;
        $rewritten = 0;
        $feedId = (int)($feed['id'] ?? 0);
        $feedName = (string)($feed['name'] ?? '');

        try {
            $items = Extractor::extractFromList($feed, false);

            foreach ($items as $item) {
                $originalHash = self::itemHash($item);
                $item = self::translateItemIfNeeded($item, $feed);
                $afterTranslateHash = self::itemHash($item);

                if ($afterTranslateHash !== $originalHash) {
                    $translated++;
                }

                $item = self::rewriteItemIfNeeded($item, $feed);

                if (self::itemHash($item) !== $afterTranslateHash) {
                    $rewritten++;
                }

                self::upsertItem($feedId, $item);
                $count++;
            }

            $next = date('Y-m-d H:i:s', time() + max(2, (int)($feed['interval_minutes'] ?? 60)) * 60);

            $stmt = $pdo->prepare('UPDATE feeds SET last_fetch_at = ?, next_fetch_at = ?, last_status = ?, last_error = ?, last_count = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([
                now(),
                $next,
                'ok',
                '',
                $count,
                now(),
                $feedId
            ]);

            $message = 'Feed atualizado com ' . $count . ' item(ns).';

            if ($translated > 0) {
                $message .= ' Tradução aplicada em ' . $translated . ' item(ns).';
            }

            if ($rewritten > 0) {
                $message .= ' Reescrita SEO aplicada em ' . $rewritten . ' item(ns).';
            }

            self::log($feedId, 'ok', $message);

            return [
                'id' => $feedId,
                'name' => $feedName,
                'ok' => true,
                'count' => $count,
                'translated' => $translated,
                'rewritten' => $rewritten,
                'next_fetch_at' => $next
            ];
        } catch (Throwable $e) {
            $next = date('Y-m-d H:i:s', time() + max(5, (int)($feed['interval_minutes'] ?? 60)) * 60);

            $stmt = $pdo->prepare('UPDATE feeds SET last_fetch_at = ?, next_fetch_at = ?, last_status = ?, last_error = ?, last_count = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([
                now(),
                $next,
                'erro',
                mb_substr($e->getMessage(), 0, 900, 'UTF-8'),
                0,
                now(),
                $feedId
            ]);

            self::log($feedId, 'erro', $e->getMessage());

            return [
                'id' => $feedId,
                'name' => $feedName,
                'ok' => false,
                'error' => $e->getMessage(),
                'next_fetch_at' => $next
            ];
        }
    }

    private static function translateItemIfNeeded(array $item, array $feed): array
    {
        if ((int)($feed['translate_enabled'] ?? 0) !== 1) {
            return $item;
        }

        if (!class_exists('AiTranslator')) {
            self::log((int)($feed['id'] ?? 0), 'erro', 'Tradução ativada, mas o arquivo app/AiTranslator.php não foi encontrado ou não foi carregado.');

            return $item;
        }

        try {
            return AiTranslator::translateItem($item, $feed);
        } catch (Throwable $e) {
            $title = mb_substr((string)($item['title'] ?? ''), 0, 120, 'UTF-8');
            self::log((int)($feed['id'] ?? 0), 'erro', 'Falha ao traduzir item "' . $title . '": ' . $e->getMessage());

            return $item;
        }
    }

    private static function rewriteItemIfNeeded(array $item, array $feed): array
    {
        if ((int)($feed['rewrite_enabled'] ?? 0) !== 1) {
            return $item;
        }

        if (!class_exists('AiTranslator')) {
            self::log((int)($feed['id'] ?? 0), 'erro', 'Reescrita ativada, mas o arquivo app/AiTranslator.php não foi encontrado ou não foi carregado.');

            return $item;
        }

        try {
            return AiTranslator::rewriteItem($item, $feed);
        } catch (Throwable $e) {
            $title = mb_substr((string)($item['title'] ?? ''), 0, 120, 'UTF-8');
            self::log((int)($feed['id'] ?? 0), 'erro', 'Falha ao reescrever item "' . $title . '": ' . $e->getMessage());

            return $item;
        }
    }

    private static function upsertItem(int $feedId, array $item): void
    {
        $url = (string)($item['url'] ?? '');

        if ($url === '') {
            return;
        }

        $guid = sha1($url);
        $hash = self::itemHash($item);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO items(feed_id, guid, url, title, summary, content, image, published_at, created_at, updated_at, hash)
             VALUES(:feed_id, :guid, :url, :title, :summary, :content, :image, :published_at, :created_at, :updated_at, :hash)
             ON CONFLICT(feed_id, guid) DO UPDATE SET
             title = excluded.title,
             summary = excluded.summary,
             content = excluded.content,
             image = excluded.image,
             published_at = COALESCE(excluded.published_at, items.published_at),
             updated_at = excluded.updated_at,
             hash = excluded.hash'
        );

        $stmt->execute([
            'feed_id' => $feedId,
            'guid' => $guid,
            'url' => $url,
            'title' => mb_substr((string)($item['title'] ?? $url), 0, 500, 'UTF-8'),
            'summary' => (string)($item['summary'] ?? ''),
            'content' => (string)($item['content'] ?? ''),
            'image' => (string)($item['image'] ?? ''),
            'published_at' => $item['published_at'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
            'hash' => $hash
        ]);
    }

    private static function itemHash(array $item): string
    {
        $title = (string)($item['title'] ?? '');
        $summary = (string)($item['summary'] ?? '');
        $content = (string)($item['content'] ?? '');
        $publishedAt = (string)($item['published_at'] ?? '');

        return sha1($title . $summary . $content . $publishedAt);
    }

    public static function log($feedId, string $level, string $message): void
    {
        try {
            $stmt = Database::pdo()->prepare('INSERT INTO logs(feed_id, level, message, created_at) VALUES(?, ?, ?, ?)');
            $stmt->execute([
                $feedId,
                $level,
                mb_substr($message, 0, 1000, 'UTF-8'),
                now()
            ]);
        } catch (Throwable $e) {
        }
    }
}
