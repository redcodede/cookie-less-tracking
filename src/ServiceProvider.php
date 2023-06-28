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
    }

    public function bootAddon()
    {
        Statamic::afterInstalled(function ($command) {
            CookielessTracking::install();
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
