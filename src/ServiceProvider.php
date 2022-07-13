<?php

namespace Redcodede\CookieLessTracking;

use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    protected $listen = [
        \Statamic\Events\ResponseCreated::class => [
            \Redcodede\CookieLessTracking\Listeners\TrackPageView::class,
        ],
        \Statamic\Events\FormSubmitted::class => [
            \Redcodede\CookieLessTracking\Listeners\TrackFormSubmission::class,
        ]
    ];

    public function bootAddon()
    {
        Statamic::afterInstalled(function ($command) {
            CookielessTracking::install();
        });
    }
}
