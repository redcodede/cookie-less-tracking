<?php

namespace Redcodede\CookieLessTracking\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Redcodede\CookieLessTracking\History\HistoryCompactor;
use Redcodede\CookieLessTracking\History\HistorySchema;
use Redcodede\CookieLessTracking\Reporting\StatisticsRepository;

class HistoryCompactorTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE page_views (
    id INTEGER PRIMARY KEY,
    session_id INTEGER,
    user_id TEXT,
    http_useragent INTEGER,
    http_accept TEXT,
    http_referer TEXT,
    event_time TEXT,
    event_name TEXT,
    event_category TEXT,
    event_target TEXT,
    event_uri TEXT,
    event_label TEXT,
    event_value INTEGER,
    tenant_id TEXT,
    campaign_id TEXT
);

CREATE VIEW analytics_events AS
SELECT * FROM page_views
WHERE (http_useragent LIKE 'mozilla%' OR http_useragent LIKE 'opera%')
    AND NOT (http_useragent LIKE '%bot%' OR http_useragent LIKE '%crawl%' OR http_useragent LIKE '%spider%' OR http_useragent LIKE '%grab%' OR http_useragent LIKE '%headless%')
    AND NOT (http_useragent LIKE '%google%' OR http_useragent LIKE '%bing%' OR http_useragent LIKE '%lighthouse%' OR http_useragent LIKE '%qwant%');
SQL);
    }

    public function test_it_compacts_old_raw_rows_and_preserves_reporting(): void
    {
        $this->event('a', '2026-01-10 10:00:00 UTC', 'page_view', '/');
        $this->event('a', '2026-01-10 10:01:00 UTC', 'file_download', '/file.pdf?token=secret');
        $this->event('b', '2026-01-10 11:00:00 UTC', 'page_view', '/contact');
        $this->event('recent', '2026-05-01 10:00:00 UTC', 'page_view', '/recent');

        $compactor = new HistoryCompactor($this->pdo, 'UTC');
        $result = $compactor->compact('2026-04-01');
        $repository = new StatisticsRepository($this->pdo, 'UTC');
        $daily = $repository->daily('2026-01-10', '2026-01-10', 'file_download');

        self::assertSame(1, $result['days']);
        self::assertSame(3, $result['raw_rows']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM page_views')->fetchColumn());
        self::assertSame(2, $daily[0]['sessions']);
        self::assertSame(2, $daily[0]['views']);
        self::assertSame(1, $daily[0]['downloads']);
        self::assertSame(1, $daily[0]['conversions']);
        self::assertSame(1, $daily[0]['bounces']);
        self::assertSame(30, $daily[0]['avg_duration_seconds']);
        $downloads = $repository->downloads('2026-01-01', '2026-01-31', 1, 10);
        self::assertSame(1, $downloads['data'][0]['events']);
        self::assertSame('/file.pdf', $downloads['data'][0]['event_uri']);
        self::assertTrue($repository->historyStatus()['enabled']);
    }

    public function test_dry_run_does_not_create_history_or_delete_raw_rows(): void
    {
        $this->event('a', '2026-01-10 10:00:00 UTC', 'page_view', '/');

        $result = (new HistoryCompactor($this->pdo, 'UTC'))
            ->compact('2026-04-01', true);

        self::assertSame(1, $result['raw_rows']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM page_views')->fetchColumn());
        self::assertFalse(HistorySchema::isInstalled($this->pdo));
    }

    public function test_compaction_is_idempotent_after_raw_rows_are_removed(): void
    {
        $this->event('a', '2026-01-10 10:00:00 UTC', 'page_view', '/');
        $compactor = new HistoryCompactor($this->pdo, 'UTC');

        $compactor->compact('2026-04-01');
        $secondRun = $compactor->compact('2026-04-01');

        self::assertSame(0, $secondRun['days']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM clt_archive_days')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT SUM(event_count) FROM clt_daily_events')->fetchColumn());
    }

    private function event(string $session, string $date, string $name, string $uri): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO page_views (
    session_id,
    http_useragent,
    event_time,
    event_name,
    event_category,
    event_uri,
    event_value
) VALUES (
    :session,
    'Mozilla/5.0 Test Browser',
    :event_time,
    :event_name,
    :event_category,
    :event_uri,
    0
)
SQL);
        $statement->execute([
            'session' => $session,
            'event_time' => (string) strtotime($date),
            'event_name' => $name,
            'event_category' => $name === 'form_submit' ? 'conversion' : 'engagement',
            'event_uri' => $uri,
        ]);
    }
}
