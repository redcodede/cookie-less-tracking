<?php

namespace Redcodede\CookieLessTracking\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Redcodede\CookieLessTracking\Reporting\StatisticsRepository;

class StatisticsRepositoryTest extends TestCase
{
    private PDO $pdo;
    private StatisticsRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE analytics_events (
    session_id TEXT,
    event_time INTEGER,
    event_name TEXT,
    event_category TEXT,
    event_uri TEXT,
    event_label TEXT
)
SQL);
        $this->repository = new StatisticsRepository($this->pdo);
    }

    public function test_it_returns_empty_reporting_data(): void
    {
        self::assertSame([], $this->repository->daily('2026-01-01', '2026-01-31'));
        self::assertSame(0, $this->repository->pages('2026-01-01', '2026-01-31', 1, 25)['meta']['total']);
    }

    public function test_it_filters_aggregates_sorts_and_paginates_events(): void
    {
        $this->event('a', '2026-01-10 10:00:00', 'page_view', '/about?x=<script>');
        $this->event('a', '2026-01-10 10:00:03', 'page_view', '/about?x=<script>');
        $this->event('b', '2026-01-10 11:00:00', 'page_view', '/');
        $this->event('b', '2025-12-01 11:00:00', 'page_view', '/outside-range');

        $daily = $this->repository->daily('2026-01-01', '2026-01-31');
        $pages = $this->repository->pages('2026-01-01', '2026-01-31', 1, 10);

        self::assertSame(2, $daily[0]['sessions']);
        self::assertSame(3, $daily[0]['views']);
        self::assertSame('/about?x=<script>', $pages['data'][0]['event_uri']);
        self::assertSame(2, $pages['data'][0]['events']);
        self::assertSame(2, $pages['meta']['total']);
    }

    public function test_it_groups_media_by_uri_and_label(): void
    {
        $this->event('a', '2026-01-10 10:00:00', 'media_used', '/video.mp4', 'loaded');
        $this->event('b', '2026-01-10 10:01:00', 'media_used', '/video.mp4', 'requested');

        $media = $this->repository->media('2026-01-01', '2026-01-31', 1, 10);

        self::assertSame(2, $media['meta']['total']);
        self::assertSame(['loaded', 'requested'], array_column($media['data'], 'event_label'));
    }

    public function test_a_bounce_is_one_page_view_without_another_event(): void
    {
        $this->event('single-page', '2026-01-10 10:00:00', 'page_view', '/');

        $this->event('engaged', '2026-01-10 11:00:00', 'page_view', '/');
        $this->event('engaged', '2026-01-10 11:01:00', 'file_download', '/file.pdf');

        $this->event('download-only', '2026-01-10 12:00:00', 'file_download', '/file.pdf');

        $this->event('two-pages', '2026-01-10 13:00:00', 'page_view', '/');
        $this->event('two-pages', '2026-01-10 13:01:00', 'page_view', '/contact');

        $daily = $this->repository->daily('2026-01-10', '2026-01-10');

        self::assertSame(4, $daily[0]['sessions']);
        self::assertSame(1, $daily[0]['bounces']);
    }

    public function test_average_duration_includes_single_event_and_long_sessions(): void
    {
        $this->event('single', '2026-01-10 10:00:00', 'page_view', '/');

        $this->event('short', '2026-01-10 11:00:00', 'page_view', '/');
        $this->event('short', '2026-01-10 11:02:00', 'page_view', '/contact');

        $this->event('long', '2026-01-10 12:00:00', 'page_view', '/');
        $this->event('long', '2026-01-10 14:00:00', 'page_view', '/contact');

        $daily = $this->repository->daily('2026-01-10', '2026-01-10');

        self::assertSame(2440, $daily[0]['avg_duration_seconds']);
    }

    public function test_the_conversion_event_is_selected_by_the_report_user(): void
    {
        $this->event('a', '2026-01-10 10:00:00', 'page_view', '/');
        $this->event('a', '2026-01-10 10:01:00', 'file_download', '/file.pdf');
        $this->event('a', '2026-01-10 10:02:00', 'form_submit', '/contact');

        $downloads = $this->repository->daily(
            '2026-01-10',
            '2026-01-10',
            'file_download',
        );
        $pageViews = $this->repository->daily(
            '2026-01-10',
            '2026-01-10',
            'page_view',
        );

        self::assertSame(1, $downloads[0]['conversions']);
        self::assertSame(1, $pageViews[0]['conversions']);
        self::assertContains('form_submit', $this->repository->eventNames());
    }

    public function test_dates_use_the_configured_statamic_timezone(): void
    {
        $this->event('a', '2026-01-01 23:30:00 UTC', 'page_view', '/');
        $repository = new StatisticsRepository($this->pdo, 'Europe/Berlin');

        self::assertSame([], $repository->daily('2026-01-01', '2026-01-01'));
        self::assertSame('2026-01-02', $repository->daily('2026-01-02', '2026-01-02')[0]['label']);
    }

    public function test_it_reads_the_unchanged_legacy_database_schema(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(<<<'SQL'
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
        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO page_views (
    session_id,
    http_useragent,
    event_time,
    event_name,
    event_category,
    event_uri,
    event_value
) VALUES (
    :session_id,
    :user_agent,
    :event_time,
    :event_name,
    :event_category,
    :event_uri,
    0
)
SQL);
        $statement->execute([
            'session_id' => 'f6c99d66b032b0c436b26b0520e812a9',
            'user_agent' => 'Mozilla/5.0 Legacy Browser',
            'event_time' => (string) strtotime('2026-01-10 10:00:00 UTC'),
            'event_name' => 'page_view',
            'event_category' => 'engagement',
            'event_uri' => '/legacy',
        ]);

        $repository = new StatisticsRepository($pdo, 'UTC');
        $daily = $repository->daily('2026-01-10', '2026-01-10');

        self::assertTrue($repository->isAvailable());
        self::assertSame(1, $daily[0]['sessions']);
        self::assertSame(1, $daily[0]['views']);
        self::assertSame(1, $daily[0]['bounces']);
        self::assertSame(0, $daily[0]['avg_duration_seconds']);
        self::assertSame('/legacy', $repository->pages('2026-01-10', '2026-01-10', 1, 10)['data'][0]['event_uri']);
    }

    private function event(
        string $session,
        string $date,
        string $name,
        string $uri,
        ?string $label = null,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO analytics_events VALUES (:session, :time, :name, :category, :uri, :label)'
        );
        $statement->execute([
            'session' => $session,
            'time' => strtotime($date),
            'name' => $name,
            'category' => $name === 'form_submit' ? 'conversion' : 'engagement',
            'uri' => $uri,
            'label' => $label,
        ]);
    }
}
