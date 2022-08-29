<?php

namespace Redcodede\CookieLessTracking;

class CookielessTracking
{
    public const TRACKING_DB = __DIR__.'/../tracking.sqlite';

    public static function install()
    {
        /**
         * @Todo:
         *      - Check if PDO Extension and SQLite Extension exist
         *      - Check if Folder is writable
         */

        // Check if sqlite file exists
        if (file_exists(self::TRACKING_DB)) return;

        // create sqlite file and migrate schema
        touch(self::TRACKING_DB);
        $pdo = new \PDO('sqlite:'.self::TRACKING_DB);
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

    private static function prepareStatement() {
        $pdo = new \PDO('sqlite:'.self::TRACKING_DB);
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
        if ( ! file_exists(self::TRACKING_DB)) return;

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
        if ( ! file_exists(self::TRACKING_DB)) return;

        $stmt = self::prepareStatement();
        $stmt->execute(array_merge(self::getDefaultValues(), [
            'event_name' => 'form_submit',
            'event_category' => 'conversion',
            'event_target' => $event_target,
            'event_label' => $event_label,
        ]));
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
            'user_id' => 'do not track',
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
}
