@extends('statamic::layout')

@section('title', 'Cookie Less Tracking')

@section('content')
    <header class="mb-6">
        <h1>Cookie Less Tracking</h1>
    </header>

    <cookie-less-tracking-report
        report-url="{{ cp_route('cookie-less-tracking.report') }}"
        initial-start="{{ $start }}"
        initial-end="{{ $end }}"
        :database-size="{{ $databaseSize }}"
    ></cookie-less-tracking-report>
@endsection
