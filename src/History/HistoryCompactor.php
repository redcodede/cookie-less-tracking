<?php

namespace Redcodede\CookieLessTracking\History;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

class HistoryCompactor
{
    private readonly DateTimeZone $timezone;

    public function __construct(
        private readonly PDO $pdo,
        string $timezone,
        private readonly bool $preserveQueryStrings = false,
    ) {
        $this->timezone = new DateTimeZone($timezone);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA busy_timeout = 10000');
        $this->pdo->exec('PRAGMA secure_delete = ON');
        $this->registerDateFunction();
    }

    public function defaultCutoff(int $retentionMonths): string
    {
        return CarbonImmutable::now($this->timezone)
            ->startOfDay()
            ->subMonthsNoOverflow(max(1, $retentionMonths))
            ->toDateString();
    }

    public function compact(string $cutoffDay, bool $dryRun = false): array
    {
        $this->assertCutoff($cutoffDay);

        if (! $dryRun) {
            HistorySchema::ensure($this->pdo);
        }

        if (HistorySchema::isInstalled($this->pdo)) {
            $this->assertCompatibleHistory();
        }

        $days = $this->candidateDays($cutoffDay);
        if (! $dryRun && $days !== []) {
            $this->pdo->exec(
                'CREATE INDEX IF NOT EXISTS clt_page_views_event_time_index
                 ON page_views (CAST(event_time AS INTEGER))'
            );
        }
        $result = [
            'cutoff_day' => $cutoffDay,
            'timezone' => $this->timezone->getName(),
            'dry_run' => $dryRun,
            'days' => 0,
            'raw_rows' => 0,
            'analytics_events' => 0,
            'history_rows' => 0,
            'skipped_days' => [],
        ];

        foreach ($days as $day) {
            $preview = $this->summarizeDay($day);

            if ($this->isArchived($day)) {
                if ($preview['raw_rows'] > 0) {
                    throw new RuntimeException(
                        "Archived day {$day} contains new raw rows. Refusing to overwrite its history."
                    );
                }

                $result['skipped_days'][] = $day;
                continue;
            }

            $result['days']++;
            $result['raw_rows'] += $preview['raw_rows'];
            $result['analytics_events'] += $preview['analytics_events'];
            $result['history_rows'] += count($preview['events']);

            if (! $dryRun) {
                $this->archiveDay($day, $preview);
            }
        }

        return $result;
    }

    private function archiveDay(string $day, array $preview): void
    {
        $now = time();
        $transactionStarted = false;

        try {
            $this->pdo->exec('BEGIN IMMEDIATE');
            $transactionStarted = true;
            $current = $this->summarizeDay($day);

            if ($current['raw_rows'] !== $preview['raw_rows']
                || $current['analytics_events'] !== $preview['analytics_events']) {
                throw new RuntimeException("Raw data changed while preparing archive day {$day}.");
            }

            $sessionStatement = $this->pdo->prepare(<<<'SQL'
INSERT INTO clt_daily_sessions (
    day,
    sessions,
    bounces,
    duration_seconds_sum,
    duration_samples,
    timezone,
    definition_version,
    archived_at
) VALUES (
    :day,
    :sessions,
    :bounces,
    :duration_sum,
    :duration_samples,
    :timezone,
    :definition_version,
    :archived_at
)
SQL);
            $sessionStatement->execute([
                'day' => $day,
                'sessions' => $current['sessions']['sessions'],
                'bounces' => $current['sessions']['bounces'],
                'duration_sum' => $current['sessions']['duration_seconds_sum'],
                'duration_samples' => $current['sessions']['duration_samples'],
                'timezone' => $this->timezone->getName(),
                'definition_version' => HistorySchema::DEFINITION_VERSION,
                'archived_at' => $now,
            ]);

            $eventStatement = $this->pdo->prepare(<<<'SQL'
INSERT INTO clt_daily_events (
    day,
    event_name,
    event_uri,
    event_label,
    event_count,
    archived_at
) VALUES (
    :day,
    :event_name,
    :event_uri,
    :event_label,
    :event_count,
    :archived_at
)
SQL);
            foreach ($current['events'] as $event) {
                $eventStatement->execute([
                    'day' => $day,
                    'event_name' => $event['event_name'],
                    'event_uri' => $event['event_uri'],
                    'event_label' => $event['event_label'],
                    'event_count' => $event['event_count'],
                    'archived_at' => $now,
                ]);
            }

            $archivedEventCount = (int) $this->scalar(
                'SELECT COALESCE(SUM(event_count), 0) FROM clt_daily_events WHERE day = :day',
                ['day' => $day],
            );
            if ($archivedEventCount !== $current['analytics_events']) {
                throw new RuntimeException("Event verification failed for archive day {$day}.");
            }

            $archiveStatement = $this->pdo->prepare(<<<'SQL'
INSERT INTO clt_archive_days (
    day,
    timezone,
    definition_version,
    url_policy,
    raw_rows,
    analytics_events,
    archived_at
) VALUES (
    :day,
    :timezone,
    :definition_version,
    :url_policy,
    :raw_rows,
    :analytics_events,
    :archived_at
)
SQL);
            $archiveStatement->execute([
                'day' => $day,
                'timezone' => $this->timezone->getName(),
                'definition_version' => HistorySchema::DEFINITION_VERSION,
                'url_policy' => $this->preserveQueryStrings ? 'full' : 'path_only',
                'raw_rows' => $current['raw_rows'],
                'analytics_events' => $current['analytics_events'],
                'archived_at' => $now,
            ]);

            [$startEpoch, $endEpoch] = $this->dayBounds($day);
            $delete = $this->pdo->prepare(
                'DELETE FROM page_views
                 WHERE CAST(event_time AS INTEGER) >= :start_epoch
                 AND CAST(event_time AS INTEGER) < :end_epoch'
            );
            $delete->execute([
                'start_epoch' => $startEpoch,
                'end_epoch' => $endEpoch,
            ]);

            if ($delete->rowCount() !== $current['raw_rows']) {
                throw new RuntimeException("Raw row deletion verification failed for archive day {$day}.");
            }

            $this->pdo->exec('COMMIT');
            $transactionStarted = false;
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                try {
                    $this->pdo->exec('ROLLBACK');
                } catch (Throwable) {
                    // Preserve the original compaction exception.
                }
            }

            throw $exception;
        }
    }

    private function summarizeDay(string $day): array
    {
        [$startEpoch, $endEpoch] = $this->dayBounds($day);
        $bindings = [
            'start_epoch' => $startEpoch,
            'end_epoch' => $endEpoch,
        ];
        $where = <<<'SQL'
CAST(event_time AS INTEGER) >= :start_epoch
AND CAST(event_time AS INTEGER) < :end_epoch
SQL;

        $rawRows = (int) $this->scalar(
            "SELECT COUNT(*) FROM page_views WHERE {$where}",
            $bindings,
        );
        $analyticsEvents = (int) $this->scalar(
            "SELECT COUNT(*) FROM analytics_events WHERE {$where}",
            $bindings,
        );
        $sessions = $this->selectOne(<<<SQL
SELECT
    COUNT(*) AS sessions,
    COALESCE(SUM(CASE WHEN views = 1 AND events = 1 THEN 1 ELSE 0 END), 0) AS bounces,
    COALESCE(SUM(duration), 0) AS duration_seconds_sum,
    COUNT(*) AS duration_samples
FROM (
    SELECT
        session_id,
        COUNT(*) AS events,
        SUM(CASE WHEN event_name = 'page_view' THEN 1 ELSE 0 END) AS views,
        MAX(CAST(event_time AS INTEGER)) - MIN(CAST(event_time AS INTEGER)) AS duration
    FROM analytics_events
    WHERE {$where}
    GROUP BY session_id
)
SQL, $bindings);
        $eventUri = $this->preserveQueryStrings
            ? "COALESCE(event_uri, '')"
            : "CASE
                WHEN instr(COALESCE(event_uri, ''), '?') > 0
                THEN substr(COALESCE(event_uri, ''), 1, instr(COALESCE(event_uri, ''), '?') - 1)
                ELSE COALESCE(event_uri, '')
            END";
        $events = $this->select(<<<SQL
SELECT
    COALESCE(event_name, '') AS event_name,
    {$eventUri} AS event_uri,
    COALESCE(event_label, '') AS event_label,
    COUNT(*) AS event_count
FROM analytics_events
WHERE {$where}
GROUP BY COALESCE(event_name, ''), {$eventUri}, COALESCE(event_label, '')
ORDER BY event_name, event_uri, event_label
SQL, $bindings);

        return [
            'raw_rows' => $rawRows,
            'analytics_events' => $analyticsEvents,
            'sessions' => [
                'sessions' => (int) ($sessions['sessions'] ?? 0),
                'bounces' => (int) ($sessions['bounces'] ?? 0),
                'duration_seconds_sum' => (int) ($sessions['duration_seconds_sum'] ?? 0),
                'duration_samples' => (int) ($sessions['duration_samples'] ?? 0),
            ],
            'events' => array_map(static function (array $event): array {
                $event['event_count'] = (int) $event['event_count'];

                return $event;
            }, $events),
        ];
    }

    private function candidateDays(string $cutoffDay): array
    {
        $cutoffEpoch = (new DateTimeImmutable(
            $cutoffDay.' 00:00:00',
            $this->timezone,
        ))->getTimestamp();
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT DISTINCT statamic_date(event_time) AS day
FROM page_views
WHERE CAST(event_time AS INTEGER) > 0
AND CAST(event_time AS INTEGER) < :cutoff_epoch
ORDER BY day ASC
SQL);
        $statement->execute(['cutoff_epoch' => $cutoffEpoch]);

        return array_values(array_filter(
            $statement->fetchAll(PDO::FETCH_COLUMN),
            static fn ($day): bool => is_string($day) && $day !== '',
        ));
    }

    private function isArchived(string $day): bool
    {
        if (! HistorySchema::isInstalled($this->pdo)) {
            return false;
        }

        return (int) $this->scalar(
            'SELECT COUNT(*) FROM clt_archive_days WHERE day = :day',
            ['day' => $day],
        ) === 1;
    }

    private function assertCompatibleHistory(): void
    {
        $rows = $this->pdo->query(
            'SELECT DISTINCT timezone, definition_version, url_policy FROM clt_archive_days'
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return;
        }

        foreach ($rows as $row) {
            if ($row['timezone'] !== $this->timezone->getName()) {
                throw new RuntimeException(
                    "Existing history uses timezone {$row['timezone']}; configured timezone is {$this->timezone->getName()}."
                );
            }

            if ((int) $row['definition_version'] !== HistorySchema::DEFINITION_VERSION) {
                throw new RuntimeException('Existing history uses an unsupported reporting definition version.');
            }

            if ($row['url_policy'] === 'path_only' && $this->preserveQueryStrings) {
                throw new RuntimeException(
                    'Existing history removed query strings; they cannot be enabled retroactively.'
                );
            }
        }
    }

    private function assertCutoff(string $cutoffDay): void
    {
        $cutoff = DateTimeImmutable::createFromFormat('!Y-m-d', $cutoffDay, $this->timezone);
        $today = new DateTimeImmutable('today', $this->timezone);

        if (! $cutoff || $cutoff->format('Y-m-d') !== $cutoffDay || $cutoff >= $today) {
            throw new RuntimeException('The compaction cutoff must be a valid completed date before today.');
        }
    }

    private function dayBounds(string $day): array
    {
        $start = new DateTimeImmutable($day.' 00:00:00', $this->timezone);

        return [$start->getTimestamp(), $start->modify('+1 day')->getTimestamp()];
    }

    private function scalar(string $sql, array $bindings = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchColumn();
    }

    private function selectOne(string $sql, array $bindings): array
    {
        return $this->select($sql, $bindings)[0] ?? [];
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
}
