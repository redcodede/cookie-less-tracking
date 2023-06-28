@extends('statamic::layout')
@section('title', 'Cookie Less Tracking')

@section('content')
    <header class="mb-3">
        <h1>{{ 'Cookie Less Tracking' }}</h1>
    </header>

    @unless($version_check)
        <div class="bg-red-dark text-white px-2 py-1 rounded mb-2">
            <span class="text-xl font-bold "><strong>ERROR:</strong> SQLite Version is too old!</span><br>
            <small>This Addon needs SQLite >= 3.32.0</small>
        </div>
    @endunless

    <rc_main :db-stats="{{ json_encode($stats) }}" fetch-url="{{ cp_route('cookie-less-tracking.filterStats') }}"></rc_main>

    <p class="mt-2">Database Size: {{ $db_file_size }} Bytes</p>
@endsection
