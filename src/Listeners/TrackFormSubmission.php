<?php

namespace Redcodede\CookieLessTracking\Listeners;

use Redcodede\CookieLessTracking\CookielessTracking;

class TrackFormSubmission
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
     * @param  object  $event
     * @return void
     */
    public function handle($event)
    {
        try {
            CookielessTracking::trackFormSubmission($event->submission->form->handle, $event->submission->form->title);
        } catch (\Exception $exception) {}
    }
}
