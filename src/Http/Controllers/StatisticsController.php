<?php

namespace Redcodede\CookieLessTracking\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Redcodede\CookieLessTracking\CookieLessTracking;
use Redcodede\CookieLessTracking\Reporting\StatisticsRepository;
use Statamic\Http\Controllers\CP\CpController;

class StatisticsController extends CpController
{
    public function index(): View
    {
        [$start, $end] = $this->defaultDateRange();

        return view('cookie-less-tracking::statistics.index', [
            'databaseSize' => CookieLessTracking::getDbFileSize(),
            'start' => $start,
            'end' => $end,
        ]);
    }

    public function report(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);
        $repository = $this->repository();

        if (! $repository) {
            return response()->json([
                'stats' => [],
                'pages' => $this->emptyPage($filters['per_page']),
                'downloads' => $this->emptyPage($filters['per_page']),
                'media' => $this->emptyPage($filters['per_page']),
                'conversion_events' => $this->conversionEvents(),
                'history' => $this->emptyHistory(),
            ]);
        }

        return response()->json([
            'stats' => $repository->daily(
                $filters['start'],
                $filters['end'],
                $filters['conversion_event'],
            ),
            'pages' => $repository->pages(
                $filters['start'],
                $filters['end'],
                $filters['page'],
                $filters['per_page'],
            ),
            'downloads' => $repository->downloads(
                $filters['start'],
                $filters['end'],
                $filters['downloads_page'],
                $filters['per_page'],
            ),
            'media' => $repository->media(
                $filters['start'],
                $filters['end'],
                $filters['media_page'],
                $filters['per_page'],
            ),
            'conversion_events' => $this->conversionEvents($repository),
            'history' => $repository->historyStatus(),
        ]);
    }

    /**
     * Kept for backwards compatibility with existing bookmarks and integrations.
     */
    public function filterStats(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);

        return response()->json(
            $this->repository()?->daily(
                $filters['start'],
                $filters['end'],
                $filters['conversion_event'],
            ) ?? []
        );
    }

    public function filterDownloads(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);
        $page = $this->repository()?->downloads(
            $filters['start'],
            $filters['end'],
            1,
            100,
        );

        return response()->json($page['data'] ?? []);
    }

    public function filterMediaUsage(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);
        $page = $this->repository()?->media(
            $filters['start'],
            $filters['end'],
            1,
            100,
        );

        return response()->json($page['data'] ?? []);
    }

    private function validatedFilters(Request $request): array
    {
        [$defaultStart, $defaultEnd] = $this->defaultDateRange();

        $validated = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
            'page' => ['nullable', 'integer', 'min:1'],
            'downloads_page' => ['nullable', 'integer', 'min:1'],
            'media_page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'conversion_event' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
        ]);

        return [
            'start' => $validated['start'] ?? $defaultStart,
            'end' => $validated['end'] ?? $defaultEnd,
            'page' => (int) ($validated['page'] ?? 1),
            'downloads_page' => (int) ($validated['downloads_page'] ?? 1),
            'media_page' => (int) ($validated['media_page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? 25),
            'conversion_event' => $validated['conversion_event'] ?? 'form_submit',
        ];
    }

    private function repository(): ?StatisticsRepository
    {
        $path = database_path('tracking.sqlite');

        if (! is_file($path)) {
            return null;
        }

        $repository = new StatisticsRepository(
            CookieLessTracking::getPDO(),
            (string) config('app.timezone', 'UTC'),
        );

        return $repository->isAvailable() ? $repository : null;
    }

    private function defaultDateRange(): array
    {
        $today = CarbonImmutable::today();

        return [$today->subDays(14)->toDateString(), $today->toDateString()];
    }

    private function emptyPage(int $perPage): array
    {
        return [
            'data' => [],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $perPage,
                'total' => 0,
            ],
        ];
    }

    private function conversionEvents(?StatisticsRepository $repository = null): array
    {
        return array_values(array_unique(array_merge(
            ['form_submit', 'file_download', 'media_used', 'page_view'],
            $repository?->eventNames() ?? [],
        )));
    }

    private function emptyHistory(): array
    {
        return [
            'enabled' => false,
            'first_day' => null,
            'last_day' => null,
            'days' => 0,
            'raw_rows_compacted' => 0,
            'timezone' => null,
        ];
    }
}
