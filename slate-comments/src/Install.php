<?php
declare(strict_types=1);

namespace SlateModules\Comments;

use Slate\DB;

/** Creates the comments table (idempotent; portable between MySQL/MariaDB and SQLite). */
final class Install
{
    public static function run(): void
    {
        $sqlite = DB::$driver === 'sqlite';
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $int = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
        $text = $sqlite ? 'TEXT' : 'MEDIUMTEXT';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        DB::exec("CREATE TABLE IF NOT EXISTS {{comments}} (
            id $pk,
            post_id $int NOT NULL,
            parent_id $int NULL,
            author_name VARCHAR(100) NOT NULL DEFAULT '',
            author_email VARCHAR(191) NOT NULL DEFAULT '',
            author_url VARCHAR(255) NOT NULL DEFAULT '',
            user_id $int NULL,
            content $text NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            user_agent VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )$tail");
        $indexes = [
            'CREATE INDEX idx_comments_post ON {{comments}} (post_id, status, created_at)',
            'CREATE INDEX idx_comments_status ON {{comments}} (status, created_at)',
            'CREATE INDEX idx_comments_ip ON {{comments}} (ip, created_at)',
            'CREATE INDEX idx_comments_email ON {{comments}} (author_email, status)',
        ];
        foreach ($indexes as $sql) {
            try {
                DB::exec($sql);
            } catch (\PDOException) {
                // Already exists (MySQL has no CREATE INDEX IF NOT EXISTS).
            }
        }
    }
}
