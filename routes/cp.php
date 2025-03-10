<?php

use Redcodede\CookieLessTracking\Http\Controllers\StatisticsController;

Route::get('cookie-less-tracking', [StatisticsController::class, 'index'])->name('cookie-less-tracking.index');
Route::get('cookie-less-tracking/filterStats', [StatisticsController::class, 'filterStats'])->name('cookie-less-tracking.filterStats');
Route::get('cookie-less-tracking/filterDownloads', [StatisticsController::class, 'filterDownloads'])->name('cookie-less-tracking.filterDownloads');
