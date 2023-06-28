<?php

namespace Redcodede\CookieLessTracking;

use Illuminate\Support\Facades\Log;
use Redcodede\CookieLessTracking\Tags\TrackPageView;
use SQLite3;

class CookielessTracking
{

    public static function install()
    {
        if (self::versionCheck()) {
            /**
             * @Todo:
             *      - Check if Folder is writable
             */
            self::createTrackingDbFile();
            self::createAnalyticsEventView();
        } else {
            Log::error('SQLite Version is too old. Please update to at least 3.32.0');
        }
    }

    public static function versionCheck(): bool
    {
        $database = new SQLite3(':memory:');
        $version = $database->version()['versionString'];

        return version_compare($version, '3.32.0', '>=');
    }

    public static function getPDO(): \PDO
    {
        return new \PDO('sqlite:'.database_path('tracking.sqlite'));
    }

    public static function getDbFileSize()
    {
        return filesize(database_path('tracking.sqlite'));
    }

    private static function prepareStatement() {
        $pdo = self::getPDO();
        $query = <<<SQL
INSERT INTO page_views(session_id,user_id,http_useragent,http_accept,http_referer,event_time,event_name,event_category,event_target,event_uri,event_label,event_value,tenant_id,campaign_id)
values(:session_id,:user_id,:http_useragent,:http_accept,:http_referer,:event_time,:event_name,:event_category,:event_target,:event_uri,:event_label,:event_value,:tenant_id,:campaign_id);
SQL;

        return $pdo->prepare($query);
    }

    /**
     * track a standard page view
     *
     * @param string|null $event_target
     * @param string|null $event_label
     * @return void
     * @see TrackPageView::handle
     */
    public static function trackPageView(string $event_target = null, string $event_label = null)
    {
        // Do not track/break if not yet installed
        if ( ! file_exists(database_path('tracking.sqlite'))) return;

        $stmt = self::prepareStatement();
        $stmt->execute(array_merge(self::getDefaultValues(), [
            'event_name' => 'page_view',
            'event_category' => 'engagement',
            'event_target' => $event_target,
            'event_label' => $event_label,
        ]));
    }

    /**
     * track a form submission
     *
     * @param string|null $event_target
     * @param string|null $event_label
     * @return void
     * @see TrackFormSubmission::handle
     */
    public static function trackFormSubmission(string $event_target = null, string $event_label = null)
    {
        // Do not track/break if not yet installed
        if ( ! file_exists(database_path('tracking.sqlite'))) return;

        $stmt = self::prepareStatement();
        $stmt->execute(array_merge(self::getDefaultValues(), [
            'event_name' => 'form_submit',
            'event_category' => 'conversion',
            'event_target' => $event_target,
            'event_label' => $event_label,
        ]));
    }

    private static function dnt_enabled(): bool
    {
        if (isset($_SERVER['HTTP_DNT'])) {
            return (bool)$_SERVER['HTTP_DNT'] ?? false;
        }
        return false;
    }

    /**
     * get a set of default values
     *
     * @return array
     */
    private static function getDefaultValues():array
    {
        return [
            'session_id' => md5(implode("", [
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $_SERVER['HTTP_ACCEPT'] ?? null,
                $_SERVER['HTTP_ACCEPT_ENCODING'] ?? null,
                $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                date("Y-m-d")
            ])),
            'user_id' => self::dnt_enabled() ? 'do not track' : null,
            'http_useragent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'http_accept' => implode("\t", [
                $_SERVER['HTTP_ACCEPT'] ?? null,
                $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
                $_SERVER['HTTP_ACCEPT_ENCODING'] ?? null,
            ]),
            'http_referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'event_time' => time(),
            'event_name' => null,
            'event_category' => null,
            'event_target' => null,
            'event_uri' => $_SERVER['REQUEST_URI'] ?? null,
            'event_label' => null,
            'event_value' => 0,
            'tenant_id' => null,
            'campaign_id' => null,
        ];
    }

    /**
     * create the tracking sqlite db file
     *
     * @return void
     */
    private static function createTrackingDbFile() {
        // Check if sqlite file exists
        if (file_exists(database_path('tracking.sqlite'))) return;

        // create sqlite file and migrate schema
        touch(database_path('tracking.sqlite'));
        $pdo = self::getPDO();
        // create table with schema
        $sql = <<<SQL
CREATE TABLE page_views (
	id INTEGER PRIMARY KEY,
	session_id INTEGER,
	user_id TEXT,
	http_useragent INTEGER,
	http_accept TEXT,
	http_referer	TEXT,
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
SQL;
        $pdo->exec($sql);
    }

    /**
     * create the view "analytics_events" to pre-filter out all bot traffic
     * - Drop the existing view if this addon has an update and the
     *   view needs to be updated
     *
     * @return void
     */
    private static function createAnalyticsEventView() {
        $sql = <<<SQL
DROP VIEW IF EXISTS analytics_events;

CREATE VIEW "analytics_events" AS
SELECT * FROM page_views
WHERE  (http_useragent LIKE 'mozilla%' OR http_useragent LIKE 'opera%')
	AND NOT (http_useragent LIKE '%bot%' OR http_useragent LIKE '%crawl%' OR http_useragent LIKE '%spider%' OR http_useragent LIKE '%grab%' OR http_useragent LIKE '%headless%')
	AND NOT (http_useragent LIKE '%google%' OR http_useragent LIKE '%bing%' OR http_useragent LIKE '%lighthouse%' OR http_useragent LIKE '%qwant%');
SQL;

        $pdo = self::getPDO();
        $pdo->exec($sql);
    }
}
