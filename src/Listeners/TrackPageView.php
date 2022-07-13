<?php

namespace Redcodede\CookieLessTracking\Listeners;

use Redcodede\CookieLessTracking\CookielessTracking;
use Statamic\Events\Event;

class TrackPageView
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  Event  $event
     * @return void
     */
    public function handle(Event $event)
    {
        CookielessTracking::trackPageView($event->data->id, $event->data->title);
    }
}
