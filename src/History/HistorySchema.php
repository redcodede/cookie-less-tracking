<?php

namespace Redcodede\CookieLessTracking\History;

use PDO;

class HistorySchema
{
    public const DEFINITION_VERSION = 1;

    public static function ensure(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS clt_daily_sessions (
    day TEXT PRIMARY KEY,
    sessions INTEGER NOT NULL,
    bounces INTEGER NOT NULL,
    duration_seconds_sum INTEGER NOT NULL,
    duration_samples INTEGER NOT NULL,
    timezone TEXT NOT NULL,
    definition_version INTEGER NOT NULL,
    archived_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS clt_daily_events (
    day TEXT NOT NULL,
    event_name TEXT NOT NULL,
    event_uri TEXT NOT NULL DEFAULT '',
    event_label TEXT NOT NULL DEFAULT '',
    event_count INTEGER NOT NULL,
    archived_at INTEGER NOT NULL,
    PRIMARY KEY (day, event_name, event_uri, event_label)
);

CREATE TABLE IF NOT EXISTS clt_archive_days (
    day TEXT PRIMARY KEY,
    timezone TEXT NOT NULL,
    definition_version INTEGER NOT NULL,
    url_policy TEXT NOT NULL,
    raw_rows INTEGER NOT NULL,
    analytics_events INTEGER NOT NULL,
    archived_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS clt_daily_events_name_day_index
    ON clt_daily_events (event_name, day);
SQL);
    }

    public static function isInstalled(PDO $pdo): bool
    {
        $statement = $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master
             WHERE type = 'table'
             AND name IN ('clt_daily_sessions', 'clt_daily_events', 'clt_archive_days')"
        );

        return (int) $statement->fetchColumn() === 3;
    }

    public static function hasArchivedDays(PDO $pdo): bool
    {
        if (! self::isInstalled($pdo)) {
            return false;
        }

        return (int) $pdo->query('SELECT COUNT(*) FROM clt_archive_days')->fetchColumn() > 0;
    }
}
