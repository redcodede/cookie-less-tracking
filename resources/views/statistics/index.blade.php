@extends('statamic::layout')
@section('title', 'Cookie Less Tracking')

@section('content')
    <header class="mb-3">
        <h1>{{ 'Cookie Less Tracking' }}</h1>
    </header>

    <rc_main :db-stats="{{ json_encode($stats) }}" fetch-url="{{ cp_route('cookie-less-tracking.filterStats') }}"></rc_main>

    <p class="mt-2">Database Size: {{ $db_file_size }} Bytes</p>
@endsection
