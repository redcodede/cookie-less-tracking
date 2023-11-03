<?php

namespace Redcodede\CookieLessTracking;

use Statamic\Providers\AddonServiceProvider;
use Statamic\Facades\CP\Nav;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $scripts = [
        __DIR__.'/../resources/dist/js/cp.js',
    ];

    protected $listen = [
        \Statamic\Events\FormSubmitted::class => [
            \Redcodede\CookieLessTracking\Listeners\TrackFormSubmission::class,
        ]
    ];

    protected $tags = [
        \Redcodede\CookieLessTracking\Tags\TrackPageView::class,
    ];

    public function boot()
    {
        parent::boot();

        $this->bootAddon();
        $this->bootAddonNav();

        $this->publishes([
            __DIR__.'/../files-for-the-public-dir/cookieLessTracking_trackPageView.php' => public_path('cookieLessTracking_trackPageView.php'),
        ], 'cookie-less-tracking-static');
        $this->publishes([
            __DIR__.'/../files-for-the-public-dir/cookieLessTracking_trackFileDownload.php' => public_path('cookieLessTracking_trackFileDownload.php'),
        ], 'cookie-less-tracking-download');
    }

    public function bootAddon()
    {
        Statamic::afterInstalled(function ($command) {
            CookieLessTracking::install();
        });
    }

    protected function bootAddonNav()
    {
        Nav::extend(function ($nav) {
            $nav->tools('Cookie Less Tracking')
                ->route('cookie-less-tracking.index')
                ->icon('seo-search-graph');
        });

        return $this;
    }

}
