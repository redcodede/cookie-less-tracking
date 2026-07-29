<?php

namespace Redcodede\CookieLessTracking;

use Illuminate\Console\Scheduling\Schedule;
use Redcodede\CookieLessTracking\Console\Commands\CompactTrackingHistory;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Facades\CP\Nav;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $vite = [
        'input' => [
            'resources/js/cp.js',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    protected $listen = [
        \Statamic\Events\FormSubmitted::class => [
            \Redcodede\CookieLessTracking\Listeners\TrackFormSubmission::class,
        ]
    ];

    protected $tags = [
        \Redcodede\CookieLessTracking\Tags\TrackPageView::class,
    ];

    protected $commands = [
        CompactTrackingHistory::class,
    ];

    public function bootAddon()
    {
        Statamic::afterInstalled(function ($command) {
            CookieLessTracking::install();
        });

        $this->bootAddonNav();

        $this->publishes([
            __DIR__.'/../files-for-the-public-dir/cookieLessTracking_trackPageView.php' => public_path('cookieLessTracking_trackPageView.php'),
        ], 'cookie-less-tracking-static');
        $this->publishes([
            __DIR__.'/../files-for-the-public-dir/cookieLessTracking_trackFileDownload.php' => public_path('cookieLessTracking_trackFileDownload.php'),
        ], 'cookie-less-tracking-download');
        $this->publishes([
            __DIR__.'/../files-for-the-public-dir/cookieLessTracking_trackMediaUsed.php' => public_path('cookieLessTracking_trackMediaUsed.php'),
        ], 'cookie-less-tracking-media');
    }

    protected function bootAddonNav(): void
    {
        Nav::extend(function ($nav) {
            $nav->tools('Cookie Less Tracking')
                ->route('cookie-less-tracking.index')
                ->icon('seo-search-graph');
        });
    }

    protected function schedule(Schedule $schedule)
    {
        if (! config('cookie-less-tracking.automatic_compaction', false)) {
            return;
        }

        $schedule
            ->command('cookie-less-tracking:compact')
            ->dailyAt((string) config('cookie-less-tracking.compaction_time', '02:15'))
            ->withoutOverlapping();
    }
}
