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
        try {
            $id_object = $this->context->get('id');
            $title_object = $this->context->get('title');
            if (is_null($id_object) || is_null($title_object)) return;

            $id = $id_object->value();
            $title = $title_object->value();
            if (is_null($id) || is_null($title)) return;

            CookieLessTracking::trackPageView($id, $title);
        } catch (\Exception $e) {
            // do nothing
        }
    }

}
