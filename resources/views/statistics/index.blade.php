@extends('statamic::layout')
@section('title', 'Cookie-less Tracking')

@section('content')
    <header class="mb-3">
        <h1>{{ 'Cookie-less Tracking' }}</h1>
    </header>

    <rc_main :db-stats="{{ json_encode($stats) }}" fetch-url="{{ cp_route('cookie-less-tracking.filterStats') }}"></rc_main>
@endsection
