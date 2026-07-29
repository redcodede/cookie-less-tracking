<?php

namespace Redcodede\CookieLessTracking\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Redcodede\CookieLessTracking\History\HistorySchema;

class StatisticsRepository
{
    private readonly DateTimeZone $timezone;

    public function __construct(
        private readonly PDO $pdo,
        string $timezone = 'UTC',
    )
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->timezone = new DateTimeZone($timezone);
        $this->registerDateFunction();
    }

    public function isAvailable(): bool
    {
        $statement = $this->pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type IN ('table', 'view') AND name = 'analytics_events'"
        );

        return (int) $statement->fetchColumn() === 1;
    }

    public function daily(
        string $start,
        string $end,
        string $conversionEvent = 'form_submit',
    ): array
    {
        $archiveExclusion = $this->hasHistory()
            ? 'AND NOT EXISTS (
                SELECT 1 FROM clt_archive_days archived
                WHERE archived.day = statamic_date(analytics_events.event_time)
            )'
            : '';
        $historyUnion = $this->hasHistory() ? <<<'SQL'
UNION ALL
SELECT
    sessions.day AS label,
    COALESCE(events.views, 0) AS views,
    COALESCE(events.downloads, 0) AS downloads,
    COALESCE(events.media, 0) AS media,
    COALESCE(events.submits, 0) AS submits,
    COALESCE(events.conversions, 0) AS conversions,
    sessions.sessions,
    sessions.duration_seconds_sum,
    sessions.duration_samples,
    sessions.bounces
FROM clt_daily_sessions sessions
LEFT JOIN (
    SELECT
        day,
        SUM(CASE WHEN event_name = 'page_view' THEN event_count ELSE 0 END) AS views,
        SUM(CASE WHEN event_name = 'file_download' THEN event_count ELSE 0 END) AS downloads,
        SUM(CASE WHEN event_name = 'media_used' THEN event_count ELSE 0 END) AS media,
        SUM(CASE WHEN event_name = 'form_submit' THEN event_count ELSE 0 END) AS submits,
        SUM(CASE WHEN event_name = :conversion_event THEN event_count ELSE 0 END) AS conversions
    FROM clt_daily_events
    WHERE day BETWEEN :start AND :end
    GROUP BY day
) events ON events.day = sessions.day
WHERE sessions.day BETWEEN :start AND :end
SQL : '';

        $sql = <<<SQL
SELECT
    label,
    SUM(views) AS views,
    SUM(downloads) AS downloads,
    SUM(media) AS media,
    SUM(submits) AS submits,
    SUM(conversions) AS conversions,
    SUM(sessions) AS sessions,
    ROUND(
        CAST(SUM(duration_seconds_sum) AS REAL)
        / NULLIF(SUM(duration_samples), 0)
    ) AS avg_duration_seconds,
    SUM(bounces) AS bounces
FROM (
    SELECT
        label,
        SUM(views) AS views,
        SUM(downloads) AS downloads,
        SUM(media) AS media,
        SUM(submits) AS submits,
        SUM(conversions) AS conversions,
        COUNT(*) AS sessions,
        SUM(duration) AS duration_seconds_sum,
        COUNT(*) AS duration_samples,
        SUM(CASE WHEN views = 1 AND events = 1 THEN 1 ELSE 0 END) AS bounces
    FROM (
        SELECT
            session_id,
            statamic_date(event_time) AS label,
            COUNT(*) AS events,
            SUM(CASE WHEN event_name = 'page_view' THEN 1 ELSE 0 END) AS views,
            SUM(CASE WHEN event_name = 'file_download' THEN 1 ELSE 0 END) AS downloads,
            SUM(CASE WHEN event_name = 'media_used' THEN 1 ELSE 0 END) AS media,
            SUM(CASE WHEN event_name = 'form_submit' THEN 1 ELSE 0 END) AS submits,
            SUM(CASE WHEN event_name = :conversion_event THEN 1 ELSE 0 END) AS conversions,
            MAX(CAST(event_time AS INTEGER)) - MIN(CAST(event_time AS INTEGER)) AS duration
        FROM analytics_events
        WHERE statamic_date(event_time) BETWEEN :start AND :end
        {$archiveExclusion}
        GROUP BY label, session_id
    )
    GROUP BY label
    {$historyUnion}
)
GROUP BY label
HAVING SUM(sessions) > 0
ORDER BY label ASC
SQL;

        return array_map(function (array $row): array {
            foreach (['views', 'downloads', 'media', 'submits', 'conversions', 'sessions', 'bounces'] as $key) {
                $row[$key] = (int) $row[$key];
            }

            $row['avg_duration_seconds'] = $row['avg_duration_seconds'] === null
                ? null
                : (int) $row['avg_duration_seconds'];

            return $row;
        }, $this->select($sql, [
            'start' => $start,
            'end' => $end,
            'conversion_event' => $conversionEvent,
        ]));
    }

    public function eventNames(): array
    {
        $historyUnion = $this->hasHistory()
            ? 'UNION SELECT event_name FROM clt_daily_events'
            : '';
        $statement = $this->pdo->query(
            "SELECT DISTINCT event_name FROM (
                SELECT event_name FROM analytics_events
                {$historyUnion}
             )
             WHERE event_name IS NOT NULL AND event_name != ''
             ORDER BY event_name ASC
             LIMIT 100"
        );

        return array_values(array_filter(
            $statement->fetchAll(PDO::FETCH_COLUMN),
            fn ($eventName): bool => is_string($eventName) && $eventName !== '',
        ));
    }

    public function historyStatus(): array
    {
        if (! $this->hasHistory()) {
            return [
                'enabled' => false,
                'first_day' => null,
                'last_day' => null,
                'days' => 0,
                'raw_rows_compacted' => 0,
                'timezone' => null,
            ];
        }

        $row = $this->pdo->query(<<<'SQL'
SELECT
    MIN(day) AS first_day,
    MAX(day) AS last_day,
    COUNT(*) AS days,
    COALESCE(SUM(raw_rows), 0) AS raw_rows_compacted,
    MAX(timezone) AS timezone
FROM clt_archive_days
SQL)->fetch(PDO::FETCH_ASSOC);

        return [
            'enabled' => (int) $row['days'] > 0,
            'first_day' => $row['first_day'],
            'last_day' => $row['last_day'],
            'days' => (int) $row['days'],
            'raw_rows_compacted' => (int) $row['raw_rows_compacted'],
            'timezone' => $row['timezone'],
        ];
    }

    public function pages(string $start, string $end, int $page, int $perPage): array
    {
        return $this->events('page_view', $start, $end, $page, $perPage);
    }

    public function downloads(string $start, string $end, int $page, int $perPage): array
    {
        return $this->events('file_download', $start, $end, $page, $perPage);
    }

    public function media(string $start, string $end, int $page, int $perPage): array
    {
        return $this->events('media_used', $start, $end, $page, $perPage, true);
    }

    private function events(
        string $eventName,
        string $start,
        string $end,
        int $page,
        int $perPage,
        bool $groupByLabel = false,
    ): array {
        $groupColumns = $groupByLabel ? 'event_uri, event_label' : 'event_uri';
        $bindings = [
            'event_name' => $eventName,
            'start' => $start,
            'end' => $end,
        ];
        $archiveExclusion = $this->hasHistory()
            ? 'AND NOT EXISTS (
                SELECT 1 FROM clt_archive_days archived
                WHERE archived.day = statamic_date(analytics_events.event_time)
            )'
            : '';
        $liveLabel = $groupByLabel ? "COALESCE(event_label, '')" : "''";
        $liveGroup = $groupByLabel ? 'event_uri, event_label' : 'event_uri';
        $historyLabel = $groupByLabel ? 'event_label' : "''";
        $historyGroup = $groupByLabel ? 'event_uri, event_label' : 'event_uri';
        $historyUnion = $this->hasHistory() ? <<<SQL
UNION ALL
SELECT
    event_uri,
    {$historyLabel} AS event_label,
    SUM(event_count) AS events,
    MIN(day) AS first_seen,
    MAX(day) AS last_seen
FROM clt_daily_events
WHERE event_name = :event_name
AND day BETWEEN :start AND :end
GROUP BY {$historyGroup}
SQL : '';
        $combinedSql = <<<SQL
SELECT
    COALESCE(event_uri, '') AS event_uri,
    {$liveLabel} AS event_label,
    COUNT(*) AS events,
    MIN(statamic_date(event_time)) AS first_seen,
    MAX(statamic_date(event_time)) AS last_seen
FROM analytics_events
WHERE event_name = :event_name
AND statamic_date(event_time) BETWEEN :start AND :end
{$archiveExclusion}
GROUP BY {$liveGroup}
{$historyUnion}
SQL;

        $countSql = "SELECT COUNT(*) FROM (
            SELECT 1 FROM ({$combinedSql}) combined GROUP BY {$groupColumns}
        )";
        $countStatement = $this->pdo->prepare($countSql);
        $countStatement->execute($bindings);
        $total = (int) $countStatement->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;

        $sql = <<<SQL
SELECT
    event_uri,
    MAX(event_label) AS event_label,
    SUM(events) AS events,
    MIN(first_seen) AS first_seen,
    MAX(last_seen) AS last_seen
FROM ({$combinedSql}) combined
GROUP BY {$groupColumns}
ORDER BY events DESC, event_uri ASC
LIMIT :limit OFFSET :offset
SQL;
        $statement = $this->pdo->prepare($sql);
        foreach ($bindings as $key => $value) {
            $statement->bindValue(":{$key}", $value);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $data = array_map(function (array $row): array {
            $row['events'] = (int) $row['events'];

            return $row;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    private function select(string $sql, array $bindings): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function registerDateFunction(): void
    {
        $timezone = $this->timezone;

        $this->pdo->sqliteCreateFunction(
            'statamic_date',
            static fn ($timestamp): ?string => $timestamp === null
                ? null
                : (new DateTimeImmutable('@'.(int) $timestamp))
                    ->setTimezone($timezone)
                    ->format('Y-m-d'),
            1,
        );
    }

    private function hasHistory(): bool
    {
        return HistorySchema::isInstalled($this->pdo);
    }
}
