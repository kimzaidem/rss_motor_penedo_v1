<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        self::$pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$pdo->exec('PRAGMA journal_mode=WAL');
        self::$pdo->exec('PRAGMA foreign_keys=ON');
        self::schema();
        return self::$pdo;
    }

    public static function schema(): void
    {
        $pdo = self::$pdo;
        if (!$pdo) {
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS feeds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            list_url TEXT NOT NULL,
            site_url TEXT NOT NULL DEFAULT '',
            token TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            interval_minutes INTEGER NOT NULL DEFAULT 60,
            max_items INTEGER NOT NULL DEFAULT 20,
            item_selector TEXT NOT NULL DEFAULT '',
            link_selector TEXT NOT NULL DEFAULT '',
            title_selector TEXT NOT NULL DEFAULT '',
            description_selector TEXT NOT NULL DEFAULT '',
            date_selector TEXT NOT NULL DEFAULT '',
            image_selector TEXT NOT NULL DEFAULT '',
            content_selector TEXT NOT NULL DEFAULT '',
            full_text INTEGER NOT NULL DEFAULT 1,
            auto_extract INTEGER NOT NULL DEFAULT 1,
            include_images INTEGER NOT NULL DEFAULT 1,
            keep_html INTEGER NOT NULL DEFAULT 1,
            require_token INTEGER NOT NULL DEFAULT 1,
            cookies TEXT NOT NULL DEFAULT '',
            user_agent TEXT NOT NULL DEFAULT '',
            include_filter TEXT NOT NULL DEFAULT '',
            exclude_filter TEXT NOT NULL DEFAULT '',
            rewrite_enabled INTEGER NOT NULL DEFAULT 0,
            rewrite_provider TEXT NOT NULL DEFAULT 'gemini',
            rewrite_model TEXT NOT NULL DEFAULT 'gemini-2.5-flash-lite',
            rewrite_prompt TEXT NOT NULL DEFAULT '',
            last_fetch_at TEXT,
            next_fetch_at TEXT,
            last_status TEXT NOT NULL DEFAULT '',
            last_error TEXT NOT NULL DEFAULT '',
            last_count INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            feed_id INTEGER NOT NULL,
            guid TEXT NOT NULL,
            url TEXT NOT NULL,
            title TEXT NOT NULL,
            summary TEXT NOT NULL DEFAULT '',
            content TEXT NOT NULL DEFAULT '',
            image TEXT NOT NULL DEFAULT '',
            published_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            hash TEXT NOT NULL DEFAULT '',
            UNIQUE(feed_id, guid),
            FOREIGN KEY(feed_id) REFERENCES feeds(id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            feed_id INTEGER,
            level TEXT NOT NULL,
            message TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY(feed_id) REFERENCES feeds(id) ON DELETE CASCADE
        )");
        foreach ([
            'site_url' => "TEXT NOT NULL DEFAULT ''",
            'active' => "INTEGER NOT NULL DEFAULT 1",
            'interval_minutes' => "INTEGER NOT NULL DEFAULT 60",
            'max_items' => "INTEGER NOT NULL DEFAULT 20",
            'item_selector' => "TEXT NOT NULL DEFAULT ''",
            'link_selector' => "TEXT NOT NULL DEFAULT ''",
            'title_selector' => "TEXT NOT NULL DEFAULT ''",
            'description_selector' => "TEXT NOT NULL DEFAULT ''",
            'date_selector' => "TEXT NOT NULL DEFAULT ''",
            'image_selector' => "TEXT NOT NULL DEFAULT ''",
            'content_selector' => "TEXT NOT NULL DEFAULT ''",
            'full_text' => "INTEGER NOT NULL DEFAULT 1",
            'auto_extract' => "INTEGER NOT NULL DEFAULT 1",
            'include_images' => "INTEGER NOT NULL DEFAULT 1",
            'keep_html' => "INTEGER NOT NULL DEFAULT 1",
            'require_token' => "INTEGER NOT NULL DEFAULT 1",
            'cookies' => "TEXT NOT NULL DEFAULT ''",
            'user_agent' => "TEXT NOT NULL DEFAULT ''",
            'include_filter' => "TEXT NOT NULL DEFAULT ''",
            'exclude_filter' => "TEXT NOT NULL DEFAULT ''",
            'rewrite_enabled' => "INTEGER NOT NULL DEFAULT 0",
            'rewrite_provider' => "TEXT NOT NULL DEFAULT 'gemini'",
            'rewrite_model' => "TEXT NOT NULL DEFAULT 'gemini-2.5-flash-lite'",
            'rewrite_prompt' => "TEXT NOT NULL DEFAULT ''",
            'last_fetch_at' => "TEXT",
            'next_fetch_at' => "TEXT",
            'last_status' => "TEXT NOT NULL DEFAULT ''",
            'last_error' => "TEXT NOT NULL DEFAULT ''",
            'last_count' => "INTEGER NOT NULL DEFAULT 0",
            'created_at' => "TEXT NOT NULL DEFAULT ''",
            'updated_at' => "TEXT NOT NULL DEFAULT ''"
        ] as $column => $definition) {
            self::addColumn('feeds', $column, $definition);
        }
        foreach ([
            'summary' => "TEXT NOT NULL DEFAULT ''",
            'content' => "TEXT NOT NULL DEFAULT ''",
            'image' => "TEXT NOT NULL DEFAULT ''",
            'published_at' => "TEXT",
            'hash' => "TEXT NOT NULL DEFAULT ''"
        ] as $column => $definition) {
            self::addColumn('items', $column, $definition);
        }
    }

    public static function addColumn(string $table, string $column, string $definition): void
    {
        $pdo = self::$pdo;
        if (!$pdo) {
            return;
        }
        $exists = false;
        $cols = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        foreach ($cols as $col) {
            if (($col['name'] ?? '') === $column) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    public static function hasUser(): bool
    {
        try {
            self::pdo();
            $count = (int)self::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
            return $count > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
