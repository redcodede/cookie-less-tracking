<?php

Route::get('cookie-less-tracking', 'StatisticsController@index')->name('cookie-less-tracking.index');
Route::get('cookie-less-tracking/filterStats', 'StatisticsController@filterStats')->name('cookie-less-tracking.filterStats');
