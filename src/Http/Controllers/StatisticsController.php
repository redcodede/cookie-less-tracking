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
	time(avg( IIF(duration BETWEEN 1 AND 1*60*60, duration ,NULL) ),'unixepoch') AS avg_duration,
	count( IIF(events=1, 1 ,NULL) ) AS bounces
FROM (
	SELECT
		tenant_id, campaign_id,
		session_id,
		strftime('%Y-%m-%d',date(event_time, 'unixepoch', 'localtime')) AS label,
		count() as events,
		count(IIF(event_name='page_view',1,NULL)) AS views,
		count(IIF(event_name='file_download',1,NULL)) AS downloads,
		count(IIF(event_name='form_submit',1,NULL)) AS submits,
		count(IIF(event_category='conversion',1,NULL)) AS conversions,
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
