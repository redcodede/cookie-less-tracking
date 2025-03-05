@extends('statamic::layout')
@section('title', 'Cookie Less Tracking')

@section('content')
    <header class="mb-3">
        <h1>{{ 'Cookie Less Tracking' }}</h1>
    </header>

    <rc_main :db-stats="{{ json_encode($stats) }}" :db-downloads="{{ json_encode($downloads) }}" fetch-url-stats="{{ cp_route('cookie-less-tracking.filterStats') }}" fetch-url-downloads="{{ cp_route('cookie-less-tracking.filterDownloads') }}"></rc_main>

    <p class="mt-2">Database Size: {{ number_format($db_file_size, 2, ',', '.') }} Bytes</p>
@endsection
