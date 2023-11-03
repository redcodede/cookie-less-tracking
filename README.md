# Cookie Less Tracking

> Cookie Less Tracking is a Statamic addon that tracks visitors GDPR compliant.

**WARNING: THIS ADDON IS STILL IN DEVELOPMENT AND MAY INTRODUCE BREAKING CHANGES WITHOUT PRIOR WARNING**

## Features

This addon tracks visitors by simple logging selected information from the server environment.
Since the addon does not rely on cookies it is GDPR and DSGVO compliant.

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

Then run this command:

    php artisan vendor:publish --tag=cookie-less-tracking-download --force

## How to Use

Browse to `Tools > Cookie Less Tracking` in the control panel to see the tracked data.


## DEV

    ln -s /var/www/html/addons/redcodede/cookie-less-tracking/resources/dist public/vendor/cookie-less-tracking
