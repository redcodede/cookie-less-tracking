<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Raw event retention
    |--------------------------------------------------------------------------
    |
    | Fully completed calendar days older than this many calendar months may
    | be aggregated into the history tables. Compaction is disabled by default
    | so an addon update can never delete existing raw data unexpectedly.
    |
    */
    'retention_months' => 3,

    /*
    | Query strings frequently contain identifiers or campaign parameters.
    | They are removed from historical URL dimensions by default while event
    | totals remain unchanged.
    */
    'preserve_query_strings_in_history' => false,

    /*
    |--------------------------------------------------------------------------
    | Automatic compaction
    |--------------------------------------------------------------------------
    |
    | This requires Laravel's scheduler to be running. The first destructive
    | run creates a consistent SQLite backup automatically.
    |
    */
    'automatic_compaction' => false,
    'compaction_time' => '02:15',

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    */
    'backup_directory' => database_path('backups/cookie-less-tracking'),
    'backup_retention_days' => 7,
];
