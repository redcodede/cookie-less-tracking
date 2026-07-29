<?php

namespace Redcodede\CookieLessTracking\Tests\Feature;

use Redcodede\CookieLessTracking\Tests\TestCase;
use Statamic\Facades\User;

class ReportingPageTest extends TestCase
{
    public function test_guest_is_redirected_from_reporting(): void
    {
        $this->get('/cp/cookie-less-tracking')->assertRedirect();
    }

    public function test_authenticated_user_can_open_reporting(): void
    {
        $this->actingAs($this->user())
            ->get('/cp/cookie-less-tracking')
            ->assertOk()
            ->assertViewIs('cookie-less-tracking::statistics.index')
            ->assertSee('cookie-less-tracking-report', false);
    }

    public function test_empty_database_returns_an_empty_report(): void
    {
        $this->actingAs($this->user())
            ->getJson('/cp/cookie-less-tracking/report?start=2026-01-01&end=2026-01-31')
            ->assertOk()
            ->assertJsonPath('stats', [])
            ->assertJsonPath('pages.meta.total', 0)
            ->assertJsonPath('history.enabled', false)
            ->assertJsonFragment(['conversion_events' => [
                'form_submit',
                'file_download',
                'media_used',
                'page_view',
            ]]);
    }

    public function test_invalid_date_range_is_rejected(): void
    {
        $this->actingAs($this->user())
            ->getJson('/cp/cookie-less-tracking/report?start=2026-02-01&end=2026-01-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end');
    }

    public function test_invalid_conversion_event_is_rejected(): void
    {
        $this->actingAs($this->user())
            ->getJson('/cp/cookie-less-tracking/report?conversion_event=form_submit%27%20OR%201%3D1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conversion_event');
    }

    private function user()
    {
        return User::make()
            ->id('reporting-test-user')
            ->email('reporting@example.com')
            ->makeSuper();
    }
}
