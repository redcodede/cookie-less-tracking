# Cookie Less Tracking

> Cookie Less Tracking is a Statamic addon that tracks visitors GDPR compliant.

**WARNING: THIS ADDON IS STILL IN DEVELOPMENT AND MAY INTRODUCE BREAKING CHANGES WITHOUT PRIOR WARNING**

## Features

This addon tracks visits by logging selected information from the server
environment without setting a tracking cookie. Cookie-free operation alone does
not establish GDPR/DSGVO compliance; the controller remains responsible for the
legal basis, transparency, retention, access controls, and the concrete tracking
configuration.

## How to Install

You can search for this addon in the `Tools > Addons` section of the Statamic control panel and click **install**, or run the following command from your project root:

``` bash
composer require redcodede/cookie-less-tracking
```
For Page views you **have to** add the `track_page_view` tag to your base layout template file.

    {{ track_page_view }}

FormSubmission's will be automatically tracked by the `TrackFormSubmission` Listener.

If you are using the full Caching Strategy you need to run

    php artisan vendor:publish --tag=cookie-less-tracking-static --force

And you have to add these lines to your .htaccess file

    # RECODEDE COOKIE LESS TRACKING for static pages
	RewriteCond %{DOCUMENT_ROOT}/static/%{REQUEST_URI}_%{QUERY_STRING}\.html -s
    RewriteCond %{REQUEST_METHOD} GET
    RewriteRule .* cookieLessTracking_trackPageView.php [L,T=text/html]

Add this to your .htaccess file to track Downloads. Adjust the download directory path accordingly.

    # RECODEDE COOKIE LESS TRACKING for downloads
    RewriteCond %{REQUEST_URI} /assets/downloads/
    RewriteCond %{REQUEST_METHOD} GET
    RewriteCond %{QUERY_STRING} ^$
    RewriteRule ^ cookieLessTracking_trackFileDownload.php [L]

Add this to your .htaccess file to track Media Files being requested. Adjust the assets directory path accordingly.

    # RECODEDE COOKIE LESS TRACKING for media other than downloads
    RewriteCond %{REQUEST_URI} /assets/videos/
    RewriteCond %{REQUEST_METHOD} GET
    RewriteCond %{QUERY_STRING} !clt=1
    RewriteRule ^ cookieLessTracking_trackMediaUsed.php [L]

With an asynchronous JavaScript call to ${url}?requested=1 you can differentiate between loaded resources and requested resources.

Then run this command:

    php artisan vendor:publish --tag=cookie-less-tracking-download --force

## How to Use

Browse to `Tools > Cookie Less Tracking` in the control panel to see the tracked data.

### Reporting definitions

- A **session** groups all events with the same pseudonymous daily identifier in the Statamic application timezone.
- A **bounce** is a session with exactly one page view and no other tracked event.
- **Average session duration** is the average time between the first and last event in each session. Single-event sessions have a duration of zero seconds.
- The **conversion event** is selected by the Control Panel user. This changes the reporting interpretation without modifying stored events.

### Tracking history and retention

Raw events remain untouched after an addon update. To inspect what would be
compacted using the configured three-calendar-month retention period:

```bash
php artisan cookie-less-tracking:compact --dry-run
```

Run the compaction explicitly:

```bash
php artisan cookie-less-tracking:compact
```

Before the first destructive run, the command creates and verifies a consistent
SQLite backup in `database/backups/cookie-less-tracking`. Each completed day is
aggregated and verified in its own transaction before its raw rows are deleted.
Page, download, media, session, bounce, duration, and selectable conversion
reporting remain available from daily history. Backups contain the original raw
data. Backups older than seven days are pruned by the next successful compaction
by default; this is configurable. Enable the scheduler or provide an equivalent
operational cleanup if seven days must be a guaranteed maximum.
Compaction enables SQLite secure deletion so removed raw records are overwritten
instead of only being placed on the internal freelist.
Historical URL query strings are removed by default because they may contain
identifiers or campaign parameters. Event totals remain unchanged.
The remaining daily aggregates must not automatically be treated as anonymous:
URL paths, event labels, and very small groups can still permit identification
or inference depending on the site's data. Document and assess the retained
dimensions before assigning a separate retention period to the history.

SQLite reuses deleted pages but does not normally return them to the filesystem.
To shrink the physical file during a maintenance window, with sufficient free
disk space:

```bash
php artisan cookie-less-tracking:compact --vacuum
```

Publish `config/cookie-less-tracking.php` to change the retention period or
enable the opt-in daily scheduler:

```bash
php artisan vendor:publish --tag=cookie-less-tracking-config
```

Automatic compaction requires Laravel's scheduler to be running. It is disabled
by default so installing or updating the addon never deletes existing raw data.

### Schedule automatic compaction on an Apache server

Statamic 6 uses Laravel's scheduler. There are two separate intervals:

- The server calls `schedule:run` **every minute** so Statamic can evaluate all
  due tasks.
- The tracking compaction itself should normally run **once per day** during a
  low-traffic maintenance window. `02:15` in the Statamic application timezone
  is the recommended default.

The Apache process does not run cron itself. The operating system's cron daemon
starts Artisan as the same service user that Apache/PHP uses, commonly
`www-data` on Debian and Ubuntu.

#### 1. Publish and configure the addon

From the Statamic project root:

```bash
php artisan vendor:publish --tag=cookie-less-tracking-config
```

Set the following values in `config/cookie-less-tracking.php`:

```php
'retention_months' => 3,
'automatic_compaction' => true,
'compaction_time' => '02:15',
'backup_retention_days' => 7,
```

The addon then registers the equivalent of:

```php
$schedule
    ->command('cookie-less-tracking:compact')
    ->dailyAt('02:15')
    ->withoutOverlapping();
```

Do not add the command a second time to `routes/console.php` when
`automatic_compaction` is enabled. If central application scheduling is
preferred, leave `automatic_compaction` disabled and add this instead:

```php
<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('cookie-less-tracking:compact')
    ->dailyAt('02:15')
    ->withoutOverlapping();
```

#### 2. Clear cached configuration and inspect the schedule

```bash
php artisan config:clear
php artisan schedule:list
php artisan cookie-less-tracking:compact --dry-run
```

Confirm that the compact command is listed once and that the displayed next run
matches the intended application timezone configured in `config/app.php`.

#### 3. Verify execution as the Apache/PHP user

Replace `/var/www/example` and `/usr/bin/php` with the actual project path and
PHP 8.3-or-newer binary:

```bash
cd /var/www/example
sudo -u www-data /usr/bin/php artisan schedule:run
sudo -u www-data /usr/bin/php artisan cookie-less-tracking:compact --dry-run
```

The service user needs read access to the project and write access to
`database/tracking.sqlite`, `database/backups/cookie-less-tracking`, `storage`,
and `bootstrap/cache`. Fix ownership or ACLs according to the server's deployment
model; do not make these locations world-writable.

#### 4. Add the system cron entry

Create `/etc/cron.d/statamic-scheduler` as `root`:

```cron
* * * * * www-data cd /var/www/example && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Recommended interval: keep this cron entry at **once per minute**. The scheduler
will only launch compaction at `02:15`; changing the server cron to once daily
can delay or skip other Statamic scheduled tasks.

For initial operation, logging can be enabled temporarily:

```cron
* * * * * www-data cd /var/www/example && /usr/bin/php artisan schedule:run >> /var/log/statamic-scheduler.log 2>&1
```

Ensure that `www-data` may append to that specific log file, then restore normal
log handling or `/dev/null` after verification. Most cron daemons detect files
in `/etc/cron.d` automatically. If the distribution requires it, restart its
cron service:

```bash
sudo systemctl restart cron
```

#### 5. Perform the first controlled run

```bash
sudo -u www-data /usr/bin/php artisan cookie-less-tracking:compact --dry-run
sudo -u www-data /usr/bin/php artisan cookie-less-tracking:compact
```

The first destructive run creates an integrity-checked backup. Use
`--vacuum` separately during a maintenance window if the physical SQLite file
must shrink immediately. Finally, verify `database/backups/cookie-less-tracking`
and the history status in the Control Panel.


## DEV

The Control Panel bundle targets Statamic 6 (Vue 3) and is built with Vite:

```bash
composer install
npm ci
npm run build
```

The `resources/dist/build` directory is published automatically by Statamic.
