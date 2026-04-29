<?php
declare(strict_types=1);

final class FeedRepository
{
    public static function all(): array
    {
        self::ensureSchemaUpdates();

        return Database::pdo()->query('SELECT f.*, (SELECT COUNT(*) FROM items i WHERE i.feed_id = f.id) AS item_count FROM feeds f ORDER BY f.id DESC')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        self::ensureSchemaUpdates();

        $stmt = Database::pdo()->prepare('SELECT * FROM feeds WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $feed = $stmt->fetch();

        return $feed ?: null;
    }

    public static function findBySlug(string $slug): ?array
    {
        self::ensureSchemaUpdates();

        $stmt = Database::pdo()->prepare('SELECT * FROM feeds WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $feed = $stmt->fetch();

        return $feed ?: null;
    }

    public static function items(int $feedId, int $limit = 50): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM items WHERE feed_id = ? ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT ?');
        $stmt->bindValue(1, $feedId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public static function recentLogs(?int $feedId = null, int $limit = 25): array
    {
        if ($feedId) {
            $stmt = Database::pdo()->prepare('SELECT * FROM logs WHERE feed_id = ? ORDER BY id DESC LIMIT ?');
            $stmt->bindValue(1, $feedId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        }

        $stmt = Database::pdo()->prepare('SELECT l.*, f.name AS feed_name FROM logs l LEFT JOIN feeds f ON f.id = l.feed_id ORDER BY l.id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public static function due(int $limit = 5): array
    {
        self::ensureSchemaUpdates();

        $stmt = Database::pdo()->prepare("SELECT * FROM feeds WHERE active = 1 AND (next_fetch_at IS NULL OR next_fetch_at = '' OR datetime(next_fetch_at) <= datetime('now', 'localtime')) ORDER BY COALESCE(next_fetch_at, '1970-01-01') ASC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public static function save(array $data, ?int $id = null): int
    {
        self::ensureSchemaUpdates();

        $name = trim((string)($data['name'] ?? ''));
        $url = trim((string)($data['list_url'] ?? ''));

        if ($name === '' || $url === '') {
            throw new RuntimeException('Informe nome do feed e URL da página/listagem.');
        }

        $oldFeed = $id ? self::find($id) : null;

        $slug = trim((string)($data['slug'] ?? ''));

        if ($slug === '') {
            $slug = self::slugify($name);
        } else {
            $slug = self::slugify($slug);
        }

        $slug = self::uniqueSlug($slug, $id);

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $siteUrl = $scheme && $host ? ($scheme . '://' . $host) : $url;
        $interval = max(2, (int)($data['interval_minutes'] ?? 60));

        $payload = [
            'name' => $name,
            'slug' => $slug,
            'list_url' => $url,
            'site_url' => $siteUrl,
            'active' => isset($data['active']) ? 1 : 0,
            'interval_minutes' => $interval,
            'max_items' => max(1, min(100, (int)($data['max_items'] ?? 20))),
            'item_selector' => trim((string)($data['item_selector'] ?? '')),
            'link_selector' => trim((string)($data['link_selector'] ?? '')),
            'title_selector' => trim((string)($data['title_selector'] ?? '')),
            'description_selector' => trim((string)($data['description_selector'] ?? '')),
            'date_selector' => trim((string)($data['date_selector'] ?? '')),
            'image_selector' => trim((string)($data['image_selector'] ?? '')),
            'content_selector' => trim((string)($data['content_selector'] ?? '')),
            'full_text' => isset($data['full_text']) ? 1 : 0,
            'auto_extract' => isset($data['auto_extract']) ? 1 : 0,
            'include_images' => isset($data['include_images']) ? 1 : 0,
            'keep_html' => isset($data['keep_html']) ? 1 : 0,
            'require_token' => isset($data['require_token']) ? 1 : 0,
            'cookies' => trim((string)($data['cookies'] ?? '')),
            'user_agent' => trim((string)($data['user_agent'] ?? '')),
            'include_filter' => trim((string)($data['include_filter'] ?? '')),
            'exclude_filter' => trim((string)($data['exclude_filter'] ?? '')),
            'translate_enabled' => !empty($data['translate_enabled']) && (string)$data['translate_enabled'] === '1' ? 1 : 0,
            'translate_provider' => trim((string)($data['translate_provider'] ?? 'gemini')),
            'translate_model' => trim((string)($data['translate_model'] ?? 'gemini-2.5-flash-lite')),
            'gemini_api_key' => trim((string)($data['gemini_api_key'] ?? '')),
            'ollama_url' => trim((string)($data['ollama_url'] ?? '')),
            'rewrite_enabled' => !empty($data['rewrite_enabled']) && (string)$data['rewrite_enabled'] === '1' ? 1 : 0,
            'rewrite_provider' => trim((string)($data['rewrite_provider'] ?? 'gemini')),
            'rewrite_model' => trim((string)($data['rewrite_model'] ?? 'gemini-2.5-flash-lite')),
            'rewrite_prompt' => trim((string)($data['rewrite_prompt'] ?? '')),
            'next_fetch_at' => self::calculateNextFetch($oldFeed, $interval),
            'updated_at' => now()
        ];

        if ($payload['translate_provider'] === '') {
            $payload['translate_provider'] = 'gemini';
        }

        if ($payload['translate_model'] === '') {
            $payload['translate_model'] = 'gemini-2.5-flash-lite';
        }

        if ($payload['rewrite_provider'] === '') {
            $payload['rewrite_provider'] = $payload['translate_provider'] ?: 'gemini';
        }

        if ($payload['rewrite_model'] === '') {
            $payload['rewrite_model'] = $payload['translate_model'] ?: 'gemini-2.5-flash-lite';
        }

        if ($payload['rewrite_prompt'] === '' && class_exists('AiTranslator')) {
            $payload['rewrite_prompt'] = AiTranslator::defaultRewritePrompt();
        }

        if ($id) {
            $payload['id'] = $id;

            $sql = 'UPDATE feeds SET
                name = :name,
                slug = :slug,
                list_url = :list_url,
                site_url = :site_url,
                active = :active,
                interval_minutes = :interval_minutes,
                max_items = :max_items,
                item_selector = :item_selector,
                link_selector = :link_selector,
                title_selector = :title_selector,
                description_selector = :description_selector,
                date_selector = :date_selector,
                image_selector = :image_selector,
                content_selector = :content_selector,
                full_text = :full_text,
                auto_extract = :auto_extract,
                include_images = :include_images,
                keep_html = :keep_html,
                require_token = :require_token,
                cookies = :cookies,
                user_agent = :user_agent,
                include_filter = :include_filter,
                exclude_filter = :exclude_filter,
                translate_enabled = :translate_enabled,
                translate_provider = :translate_provider,
                translate_model = :translate_model,
                gemini_api_key = :gemini_api_key,
                ollama_url = :ollama_url,
                rewrite_enabled = :rewrite_enabled,
                rewrite_provider = :rewrite_provider,
                rewrite_model = :rewrite_model,
                rewrite_prompt = :rewrite_prompt,
                next_fetch_at = :next_fetch_at,
                updated_at = :updated_at
                WHERE id = :id';

            Database::pdo()->prepare($sql)->execute($payload);

            return $id;
        }

        $payload['token'] = bin2hex(random_bytes(20));
        $payload['created_at'] = now();

        $sql = 'INSERT INTO feeds(
            name,
            slug,
            list_url,
            site_url,
            token,
            active,
            interval_minutes,
            max_items,
            item_selector,
            link_selector,
            title_selector,
            description_selector,
            date_selector,
            image_selector,
            content_selector,
            full_text,
            auto_extract,
            include_images,
            keep_html,
            require_token,
            cookies,
            user_agent,
            include_filter,
            exclude_filter,
            translate_enabled,
            translate_provider,
            translate_model,
            gemini_api_key,
            ollama_url,
            rewrite_enabled,
            rewrite_provider,
            rewrite_model,
            rewrite_prompt,
            next_fetch_at,
            created_at,
            updated_at
        ) VALUES(
            :name,
            :slug,
            :list_url,
            :site_url,
            :token,
            :active,
            :interval_minutes,
            :max_items,
            :item_selector,
            :link_selector,
            :title_selector,
            :description_selector,
            :date_selector,
            :image_selector,
            :content_selector,
            :full_text,
            :auto_extract,
            :include_images,
            :keep_html,
            :require_token,
            :cookies,
            :user_agent,
            :include_filter,
            :exclude_filter,
            :translate_enabled,
            :translate_provider,
            :translate_model,
            :gemini_api_key,
            :ollama_url,
            :rewrite_enabled,
            :rewrite_provider,
            :rewrite_model,
            :rewrite_prompt,
            :next_fetch_at,
            :created_at,
            :updated_at
        )';

        Database::pdo()->prepare($sql)->execute($payload);

        return (int)Database::pdo()->lastInsertId();
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM feeds WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function slugify(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?? 'feed');
        $value = trim($value, '-');

        return $value !== '' ? $value : 'feed';
    }

    private static function uniqueSlug(string $slug, ?int $ignoreId): string
    {
        $base = $slug;
        $i = 2;

        while (true) {
            $stmt = Database::pdo()->prepare('SELECT id FROM feeds WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
            $id = $stmt->fetchColumn();

            if (!$id || ($ignoreId && (int)$id === $ignoreId)) {
                return $slug;
            }

            $slug = $base . '-' . $i;
            $i++;
        }
    }

    private static function calculateNextFetch(?array $oldFeed, int $interval): string
    {
        if (!$oldFeed) {
            return now();
        }

        $lastFetch = trim((string)($oldFeed['last_fetch_at'] ?? ''));

        if ($lastFetch === '' || strtotime($lastFetch) === false) {
            return now();
        }

        $next = strtotime($lastFetch) + ($interval * 60);

        if ($next <= time()) {
            return now();
        }

        return date('Y-m-d H:i:s', $next);
    }

    private static function ensureSchemaUpdates(): void
    {
        static $done = false;

        if ($done) {
            return;
        }

        $done = true;
        $pdo = Database::pdo();

        $columns = [
            'translate_enabled INTEGER DEFAULT 0',
            'translate_provider TEXT DEFAULT "gemini"',
            'translate_model TEXT DEFAULT "gemini-2.5-flash-lite"',
            'gemini_api_key TEXT DEFAULT ""',
            'ollama_url TEXT DEFAULT ""',
            'rewrite_enabled INTEGER DEFAULT 0',
            'rewrite_provider TEXT DEFAULT "gemini"',
            'rewrite_model TEXT DEFAULT "gemini-2.5-flash-lite"',
            'rewrite_prompt TEXT DEFAULT ""'
        ];

        foreach ($columns as $column) {
            try {
                $pdo->exec('ALTER TABLE feeds ADD COLUMN ' . $column);
            } catch (Throwable $e) {
            }
        }

        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS translation_cache (
                hash TEXT PRIMARY KEY,
                provider TEXT NOT NULL,
                model TEXT NOT NULL,
                source_text TEXT NOT NULL,
                translated_text TEXT NOT NULL,
                created_at TEXT NOT NULL
            )');
        } catch (Throwable $e) {
        }
    }
}