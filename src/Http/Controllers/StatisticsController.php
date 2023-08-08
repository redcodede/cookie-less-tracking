<?php

namespace Redcodede\CookieLessTracking\Http\Controllers;

use Illuminate\Http\Request;
use Redcodede\CookieLessTracking\CookieLessTracking;
use Statamic\Http\Controllers\CP\CpController;

class StatisticsController extends CpController
{
    public function index(Request $request)
    {
        $db_file_size = CookieLessTracking::getDbFileSize();
        $version_check = CookieLessTracking::versionCheck();

        return view('cookie-less-tracking::statistics.index', [
            'stats' => $version_check ? $this->getStats() : [],
            'db_file_size' => $db_file_size,
            'version_check' => $version_check,
        ]);
    }

    public function filterStats(Request $request)
    {
        return response()->json(
            $this->getStats(
                $request->get('start'),
                $request->get('end')
            )
        );
    }

    protected function getStats(string $date_start = null, string $date_end = null) {

        $start = $date_start ? "'$date_start'" : "date('now','-14 days')";
        $end = $date_end ? "'$date_end'" : "date('now')";

        $sql = <<<SQL
SELECT
	tenant_id, campaign_id, label,
	sum(views) as views,
	sum(downloads) as downloads,
	sum(submits) as submits,
	sum(conversions) as conversions,
	count(DISTINCT session_id) AS sessions,
	time(avg( CASE WHEN duration > 1 AND duration < 1*60*60 THEN duration ELSE NULL END ),'unixepoch') AS avg_duration,
	count( CASE WHEN events = 1 THEN 1 ELSE NULL END ) AS bounces
FROM (
	SELECT
		tenant_id, campaign_id,
		session_id,
		strftime('%Y-%m-%d',date(event_time, 'unixepoch', 'localtime')) AS label,
		count() as events,
		count( CASE WHEN event_name = 'page_view' THEN 1 ELSE NULL END ) AS views,
		count( CASE WHEN event_name = 'file_download' THEN 1 ELSE NULL END ) AS downloads,
		count( CASE WHEN event_name = 'form_submit' THEN 1 ELSE NULL END ) AS submits,
		count( CASE WHEN event_category = 'conversion' THEN 1 ELSE NULL END ) AS conversions,
		(max(`event_time`) - min(`event_time`)) AS duration
	FROM analytics_events
	WHERE date(event_time, 'unixepoch', 'localtime') BETWEEN $start AND $end
	GROUP BY tenant_id, campaign_id, label, session_id
)
GROUP BY label
ORDER BY label
SQL;

        $pdo = CookieLessTracking::getPDO();
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
