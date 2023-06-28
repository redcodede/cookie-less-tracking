<?php

namespace Redcodede\CookieLessTracking\Tags;

use Redcodede\CookieLessTracking\CookieLessTracking;
use Statamic\Tags\Tags;

/**
 * This Tag only tracks Page views.
 * For FormSubmissions use the Event Listener.
 */
class TrackPageView extends Tags
{
    protected static $handle = 'track_page_view';

    /**
     * The {{ track_page_view }} tag.
     *
     * @return string|array
     */
    public function index()
    {
        $id = $this->context->get('id')->value() ?? null;
        $title = $this->context->get('title')->value() ?? null;

        if (is_null($id) || is_null($title)) return;

        CookieLessTracking::trackPageView($id, $title);
    }

}
