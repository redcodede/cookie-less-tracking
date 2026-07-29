<template>
    <div class="clt-report">
        <section class="card clt-filters">
            <div class="clt-filter-fields">
                <label>
                    <span>From</span>
                    <input v-model="start" class="input-text" type="date" :max="end" :disabled="loading">
                </label>
                <label>
                    <span>Until</span>
                    <input v-model="end" class="input-text" type="date" :min="start" :disabled="loading">
                </label>
                <label>
                    <span class="clt-label-with-info">
                        Conversion event
                        <info-button
                            label="Conversion event"
                            text="Choose which tracked event should be counted as a conversion. This changes only the reporting interpretation; the stored tracking data remains unchanged."
                        />
                    </span>
                    <select v-model="conversionEvent" class="input-text" :disabled="loading">
                        <option v-for="eventName in conversionEvents" :key="eventName" :value="eventName">
                            {{ eventLabel(eventName) }}
                        </option>
                    </select>
                </label>
                <button class="btn-primary" type="button" :disabled="loading || !validRange" @click="applyFilters">
                    {{ loading ? 'Loading…' : 'Apply filter' }}
                </button>
            </div>
            <div class="clt-storage-status">
                <p class="clt-db-size">Database size: {{ formattedDatabaseSize }}</p>
                <p v-if="history.enabled">
                    History through {{ history.last_day }} ·
                    {{ formatNumber(history.raw_rows_compacted) }} raw rows compacted
                    <info-button
                        label="Tracking history"
                        :text="`Data from ${history.first_day} through ${history.last_day} is stored as daily summaries in ${history.timezone}. Individual timestamps, sessions, user agents, referrers, and request headers from those days have been removed.`"
                    />
                </p>
            </div>
        </section>

        <div v-if="error" class="clt-alert" role="alert">
            <strong>Reporting could not be loaded.</strong>
            <span>{{ error }}</span>
            <button type="button" @click="loadReport()">Try again</button>
        </div>

        <template v-else>
            <section class="clt-metrics" aria-label="Totals">
                <article
                    v-for="metric in metrics"
                    :key="metric.key"
                    class="card clt-metric"
                    :class="{ 'is-muted': !enabledMetrics.includes(metric.key) }"
                >
                    <span class="clt-metric-label">
                        <i :style="{ backgroundColor: metric.color }"></i>
                        {{ metric.label }}
                        <info-button :label="metric.label" :text="metricDescription(metric)" />
                    </span>
                    <button
                        type="button"
                        class="clt-metric-toggle"
                        :aria-pressed="enabledMetrics.includes(metric.key)"
                        :aria-label="`${metric.label}: ${formatNumber(totals[metric.key])}. Toggle chart line.`"
                        @click="toggleMetric(metric.key)"
                    >
                        <strong>{{ formatNumber(totals[metric.key]) }}</strong>
                    </button>
                </article>
            </section>

            <section class="card clt-panel">
                <div class="clt-panel-heading">
                    <div>
                        <h2>Activity over time</h2>
                        <p>
                            {{ start }} – {{ end }}
                            <info-button
                                label="Activity chart"
                                text="Each position represents one calendar day in the Statamic application timezone. Days without events are displayed as zero."
                            />
                        </p>
                    </div>
                </div>
                <div v-if="loading" class="clt-state" aria-live="polite">Loading reporting data…</div>
                <div v-else-if="!stats.length" class="clt-state">No tracking data is available for this period.</div>
                <div v-else class="clt-chart-scroll">
                    <svg class="clt-chart" viewBox="0 0 1000 280" role="img" aria-label="Tracking activity line chart">
                        <line v-for="tick in 5" :key="tick" x1="48" x2="980" :y1="chartY((tick - 1) * chartMax / 4)" :y2="chartY((tick - 1) * chartMax / 4)" class="clt-grid" />
                        <text v-for="tick in 5" :key="`label-${tick}`" x="40" :y="chartY((tick - 1) * chartMax / 4) + 4" text-anchor="end">{{ formatNumber(Math.round((tick - 1) * chartMax / 4)) }}</text>
                        <polyline
                            v-for="line in chartLines"
                            :key="line.key"
                            :points="line.points"
                            :stroke="line.color"
                            fill="none"
                            stroke-width="3"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                        <text v-for="label in chartLabels" :key="label.index" :x="label.x" y="270" text-anchor="middle">{{ label.text }}</text>
                    </svg>
                </div>
            </section>

            <report-table
                title="Pages"
                description="Page views grouped by URL"
                :page="pages"
                :loading="loading"
                count-label="Views"
                total-label="URLs"
                @change-page="changePage('page', $event)"
            />

            <report-table
                title="Downloads"
                description="File downloads grouped by URL"
                :page="downloads"
                :loading="loading"
                count-label="Downloads"
                total-label="URLs"
                @change-page="changePage('downloads_page', $event)"
            />

            <report-table
                title="Media usage"
                description="Media requests grouped by URL and type"
                :page="media"
                :loading="loading"
                count-label="Requests"
                total-label="URL/type combinations"
                show-label
                @change-page="changePage('media_page', $event)"
            />

            <section class="card clt-panel">
                <div class="clt-panel-heading">
                    <div>
                        <h2>Daily statistics</h2>
                        <p>
                            Sessions, events, average session duration, and bounces
                            <info-button
                                label="Average session duration"
                                text="Average time between the first and last tracked event of each session. Single-event sessions contribute 0 seconds."
                            />
                        </p>
                    </div>
                </div>
                <div class="clt-table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Sessions</th>
                                <th>Views</th><th>Downloads</th>
                                <th>Media</th><th>Submits</th>
                                <th>Conversions</th>
                                <th>Avg. duration</th>
                                <th>Bounces</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in stats" :key="row.label">
                                <td>{{ row.label }}</td>
                                <td>{{ formatNumber(row.sessions) }}</td>
                                <td>{{ formatNumber(row.views) }}</td>
                                <td>{{ formatNumber(row.downloads) }}</td>
                                <td>{{ formatNumber(row.media) }}</td>
                                <td>{{ formatNumber(row.submits) }}</td>
                                <td>{{ formatNumber(row.conversions) }}</td>
                                <td>{{ formatDuration(row.avg_duration_seconds) }}</td>
                                <td>{{ formatNumber(row.bounces) }}</td>
                            </tr>
                            <tr v-if="!loading && !stats.length"><td colspan="9" class="clt-empty-cell">No daily statistics found.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>
    </div>
</template>

<script>
const emptyPage = () => ({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
});

const InfoButton = {
    name: 'CookieLessTrackingInfoButton',
    props: {
        label: { type: String, required: true },
        text: { type: String, required: true },
    },
    data() {
        return { open: false };
    },
    computed: {
        tooltipId() {
            return `clt-info-${this.$.uid}`;
        },
    },
    template: `
        <span class="clt-info" :class="{ 'is-open': open }">
            <button
                type="button"
                class="clt-info-button"
                :aria-label="'Information about ' + label"
                :aria-expanded="open"
                :aria-describedby="tooltipId"
                @click.stop="open = !open"
            >i</button>
            <span :id="tooltipId" class="clt-tooltip" role="tooltip">{{ text }}</span>
        </span>
    `,
};

const ReportTable = {
    name: 'CookieLessTrackingReportTable',
    props: {
        title: String,
        description: String,
        page: { type: Object, required: true },
        loading: Boolean,
        countLabel: String,
        totalLabel: { type: String, default: 'records' },
        showLabel: Boolean,
    },
    emits: ['change-page'],
    methods: {
        formatNumber(value) {
            return new Intl.NumberFormat().format(Number(value || 0));
        },
    },
    template: `
        <section class="card clt-panel">
            <div class="clt-panel-heading">
                <div><h2>{{ title }}</h2><p>{{ description }}</p></div>
                <span v-if="page.meta">{{ formatNumber(page.meta.total) }} {{ totalLabel }}</span>
            </div>
            <div class="clt-table-wrap">
                <table>
                    <thead><tr><th v-if="showLabel">Type</th><th>URL</th><th>{{ countLabel }}</th><th>First seen</th><th>Last seen</th></tr></thead>
                    <tbody>
                        <tr v-for="(row, index) in page.data" :key="row.event_uri + '-' + index">
                            <td v-if="showLabel">{{ row.event_label || '—' }}</td>
                            <td class="clt-url" :title="row.event_uri">{{ row.event_uri || '—' }}</td>
                            <td>{{ formatNumber(row.events) }}</td><td>{{ row.first_seen }}</td><td>{{ row.last_seen }}</td>
                        </tr>
                        <tr v-if="!loading && !page.data.length"><td :colspan="showLabel ? 5 : 4" class="clt-empty-cell">No records found.</td></tr>
                    </tbody>
                </table>
            </div>
            <nav v-if="page.meta && page.meta.last_page > 1" class="clt-pagination" :aria-label="title + ' pagination'">
                <button type="button" :disabled="loading || page.meta.current_page <= 1" @click="$emit('change-page', page.meta.current_page - 1)">Previous</button>
                <span>Page {{ page.meta.current_page }} of {{ page.meta.last_page }}</span>
                <button type="button" :disabled="loading || page.meta.current_page >= page.meta.last_page" @click="$emit('change-page', page.meta.current_page + 1)">Next</button>
            </nav>
        </section>
    `,
};

export default {
    name: 'CookieLessTrackingReport',
    components: { InfoButton, ReportTable },
    props: {
        reportUrl: { type: String, required: true },
        initialStart: { type: String, required: true },
        initialEnd: { type: String, required: true },
        databaseSize: { type: Number, default: 0 },
    },
    data() {
        return {
            start: this.initialStart,
            end: this.initialEnd,
            stats: [],
            pages: emptyPage(),
            downloads: emptyPage(),
            media: emptyPage(),
            loading: true,
            error: null,
            controller: null,
            enabledMetrics: ['sessions', 'views'],
            pageNumbers: { page: 1, downloads_page: 1, media_page: 1 },
            conversionEvent: 'form_submit',
            conversionEvents: ['form_submit', 'file_download', 'media_used', 'page_view'],
            history: {
                enabled: false,
                first_day: null,
                last_day: null,
                days: 0,
                raw_rows_compacted: 0,
                timezone: null,
            },
            metrics: [
                {
                    key: 'sessions',
                    label: 'Sessions',
                    color: '#d2007a',
                    description: 'Daily anonymous visitor identifiers. A session spans all tracked events for the same identifier on one calendar day; it resets at midnight.',
                },
                {
                    key: 'views',
                    label: 'Views',
                    color: '#0098d4',
                    description: 'Number of tracked page_view events in the selected period.',
                },
                {
                    key: 'downloads',
                    label: 'Downloads',
                    color: '#e6a700',
                    description: 'Number of tracked file_download events in the selected period.',
                },
                {
                    key: 'media',
                    label: 'Media',
                    color: '#2d9d78',
                    description: 'Number of tracked media_used events, including loaded and explicitly requested media.',
                },
                {
                    key: 'submits',
                    label: 'Form submits',
                    color: '#ef7f1a',
                    description: 'Number of tracked form_submit events. This is an observed event and remains independent of the selected conversion definition.',
                },
                {
                    key: 'conversions',
                    label: 'Conversions',
                    color: '#6b9b25',
                    description: 'Number of {{event}} events. The conversion event can be selected in the filter above.',
                },
                {
                    key: 'bounces',
                    label: 'Bounces',
                    color: '#db001b',
                    description: 'Sessions containing exactly one page view and no other tracked event.',
                },
            ],
        };
    },
    computed: {
        validRange() {
            return this.start && this.end && this.start <= this.end;
        },
        formattedDatabaseSize() {
            const units = ['bytes', 'KB', 'MB', 'GB'];
            let size = this.databaseSize;
            let unit = 0;
            while (size >= 1024 && unit < units.length - 1) {
                size /= 1024;
                unit++;
            }
            return `${new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(size)} ${units[unit]}`;
        },
        totals() {
            return this.metrics.reduce((totals, metric) => {
                totals[metric.key] = this.stats.reduce((sum, row) => sum + Number(row[metric.key] || 0), 0);
                return totals;
            }, {});
        },
        chartStats() {
            if (!this.stats.length) return [];

            const rows = new Map(this.stats.map(row => [row.label, row]));
            const result = [];
            const current = this.parseCalendarDate(this.start);
            const last = this.parseCalendarDate(this.end);

            while (current <= last) {
                const label = current.toISOString().slice(0, 10);
                result.push(rows.get(label) || {
                    label,
                    sessions: 0,
                    views: 0,
                    downloads: 0,
                    media: 0,
                    submits: 0,
                    conversions: 0,
                    bounces: 0,
                    avg_duration_seconds: null,
                });
                current.setUTCDate(current.getUTCDate() + 1);
            }

            return result;
        },
        chartMax() {
            const values = this.chartStats.flatMap(row => this.enabledMetrics.map(key => Number(row[key] || 0)));
            return Math.max(1, ...values);
        },
        chartLines() {
            return this.metrics
                .filter(metric => this.enabledMetrics.includes(metric.key))
                .map(metric => ({
                    ...metric,
                    points: this.chartStats.map((row, index) => `${this.chartX(index)},${this.chartY(Number(row[metric.key] || 0))}`).join(' '),
                }));
        },
        chartLabels() {
            const every = Math.max(1, Math.ceil(this.chartStats.length / 8));
            return this.chartStats
                .map((row, index) => ({ text: row.label.slice(5), x: this.chartX(index), index }))
                .filter(label => label.index % every === 0 || label.index === this.chartStats.length - 1);
        },
    },
    mounted() {
        this.loadReport();
    },
    beforeUnmount() {
        this.controller?.abort();
    },
    methods: {
        async loadReport() {
            this.controller?.abort();
            const controller = new AbortController();
            this.controller = controller;
            this.loading = true;
            this.error = null;

            const params = new URLSearchParams({
                start: this.start,
                end: this.end,
                per_page: '25',
                conversion_event: this.conversionEvent,
                ...Object.fromEntries(Object.entries(this.pageNumbers).map(([key, value]) => [key, String(value)])),
            });

            try {
                const response = await fetch(`${this.reportUrl}?${params}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                if (!response.ok) {
                    const body = await response.json().catch(() => ({}));
                    throw new Error(body.message || `Request failed with status ${response.status}.`);
                }
                const data = await response.json();
                this.stats = data.stats || [];
                this.pages = data.pages || emptyPage();
                this.downloads = data.downloads || emptyPage();
                this.media = data.media || emptyPage();
                this.history = data.history || this.history;
                this.conversionEvents = Array.from(new Set([
                    ...this.conversionEvents,
                    ...(data.conversion_events || []),
                    this.conversionEvent,
                ])).sort();
            } catch (error) {
                if (error.name !== 'AbortError') this.error = error.message;
            } finally {
                if (this.controller === controller) this.loading = false;
            }
        },
        applyFilters() {
            this.pageNumbers = { page: 1, downloads_page: 1, media_page: 1 };
            this.loadReport();
        },
        changePage(type, page) {
            this.pageNumbers[type] = page;
            this.loadReport();
        },
        toggleMetric(key) {
            this.enabledMetrics = this.enabledMetrics.includes(key)
                ? this.enabledMetrics.filter(item => item !== key)
                : [...this.enabledMetrics, key];
        },
        metricDescription(metric) {
            if (!metric) return '';
            return metric.description.replace('{{event}}', this.eventLabel(this.conversionEvent));
        },
        eventLabel(eventName) {
            const labels = {
                form_submit: 'Form submit',
                file_download: 'File download',
                media_used: 'Media usage',
                page_view: 'Page view',
            };
            return labels[eventName] || eventName;
        },
        parseCalendarDate(value) {
            const [year, month, day] = value.split('-').map(Number);
            return new Date(Date.UTC(year, month - 1, day));
        },
        chartX(index) {
            return this.chartStats.length <= 1 ? 514 : 48 + index * (932 / (this.chartStats.length - 1));
        },
        chartY(value) {
            return 240 - (value / this.chartMax) * 220;
        },
        formatNumber(value) {
            return new Intl.NumberFormat().format(Number(value || 0));
        },
        formatDuration(seconds) {
            if (seconds === null || seconds === undefined) return '—';
            const totalSeconds = Math.max(0, Math.round(Number(seconds)));
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const remainingSeconds = totalSeconds % 60;

            if (hours) return `${hours} h ${minutes} min ${remainingSeconds} sec`;
            if (minutes) return `${minutes} min ${remainingSeconds} sec`;
            return `${remainingSeconds} sec`;
        },
    },
};
</script>

<style>
.clt-report { display: grid; gap: 1.5rem; }
.clt-filters { padding: 1.25rem; display: flex; align-items: end; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.clt-filter-fields { display: flex; align-items: end; gap: 1rem; flex-wrap: wrap; }
.clt-filter-fields label { display: grid; gap: .375rem; font-size: .875rem; font-weight: 600; }
.clt-filter-fields input, .clt-filter-fields select { min-width: 10.5rem; }
.clt-label-with-info { display: inline-flex; align-items: center; gap: .35rem; }
.clt-db-size, .clt-panel-heading p { color: var(--clt-muted, #6b7280); font-size: .875rem; }
.clt-storage-status { display: grid; gap: .25rem; color: var(--clt-muted, #6b7280); font-size: .8rem; text-align: right; }
.clt-metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(8.5rem, 1fr)); gap: .75rem; }
.clt-metric { padding: 1rem; position: relative; text-align: left; transition: opacity .15s ease, transform .15s ease; }
.clt-metric:hover { transform: translateY(-1px); }
.clt-metric.is-muted { opacity: .48; }
.clt-metric-label { display: flex; align-items: center; gap: .45rem; font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; }
.clt-metric-label i { width: .65rem; height: .65rem; border-radius: 999px; }
.clt-metric strong { display: block; margin-top: .35rem; font-size: 1.5rem; }
.clt-metric-toggle { display: block; width: 100%; padding: 0; color: inherit; text-align: left; background: transparent; border: 0; }
.clt-metric-toggle:focus-visible, .clt-info-button:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
.clt-info { position: relative; display: inline-flex; vertical-align: middle; text-transform: none; letter-spacing: normal; }
.clt-info-button { display: inline-grid; width: 1.1rem; height: 1.1rem; padding: 0; place-items: center; border: 1px solid currentColor; border-radius: 999px; color: var(--clt-muted, #6b7280); font: 700 .7rem/1 sans-serif; background: transparent; cursor: help; }
.clt-tooltip { position: absolute; z-index: 50; left: 50%; bottom: calc(100% + .5rem); width: min(18rem, 75vw); padding: .65rem .75rem; border-radius: .375rem; color: #fff; font-size: .75rem; font-weight: 400; line-height: 1.4; text-align: left; text-transform: none; letter-spacing: normal; background: #111827; box-shadow: 0 5px 18px rgba(0, 0, 0, .2); opacity: 0; visibility: hidden; transform: translate(-50%, .25rem); transition: opacity .12s ease, transform .12s ease, visibility .12s; pointer-events: none; white-space: normal; }
.clt-info:hover .clt-tooltip, .clt-info:focus-within .clt-tooltip, .clt-info.is-open .clt-tooltip { opacity: 1; visibility: visible; transform: translate(-50%, 0); }
.clt-panel { overflow: visible; }
.clt-panel-heading { padding: 1.25rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; border-bottom: 1px solid var(--clt-border, #e5e7eb); }
.clt-panel-heading h2 { font-size: 1rem; font-weight: 700; }
.clt-panel-heading > span { font-size: .8rem; color: var(--clt-muted, #6b7280); white-space: nowrap; }
.clt-state { padding: 4rem 1rem; text-align: center; color: var(--clt-muted, #6b7280); }
.clt-chart-scroll, .clt-table-wrap { overflow-x: auto; }
.clt-chart { display: block; min-width: 720px; width: 100%; height: 280px; padding: .75rem; }
.clt-chart text { fill: var(--clt-muted, #6b7280); font-size: 12px; }
.clt-grid { stroke: var(--clt-border, #e5e7eb); stroke-width: 1; }
.clt-report table { width: 100%; border-collapse: collapse; font-size: .875rem; }
.clt-report th, .clt-report td { padding: .75rem 1rem; text-align: left; border-bottom: 1px solid var(--clt-border, #e5e7eb); white-space: nowrap; }
.clt-report th { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; color: var(--clt-muted, #6b7280); }
.clt-report th .clt-info { margin-left: .25rem; }
.clt-report tbody tr:last-child td { border-bottom: 0; }
.clt-url { min-width: 18rem; max-width: 50rem; overflow: hidden; text-overflow: ellipsis; }
.clt-empty-cell { padding: 2.5rem 1rem; text-align: center; color: var(--clt-muted, #6b7280); }
.clt-pagination { padding: .75rem 1rem; display: flex; align-items: center; justify-content: flex-end; gap: .75rem; border-top: 1px solid var(--clt-border, #e5e7eb); font-size: .8rem; }
.clt-pagination button, .clt-alert button { border: 1px solid var(--clt-border, #d1d5db); border-radius: .375rem; padding: .4rem .7rem; }
.clt-pagination button:disabled { opacity: .4; cursor: not-allowed; }
.clt-alert { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; padding: 1rem; border: 1px solid #ef4444; border-radius: .5rem; color: #991b1b; background: #fef2f2; }
.clt-alert button { margin-left: auto; }
.dark .clt-report { --clt-muted: #9ca3af; --clt-border: #374151; }
.dark .clt-alert { color: #fecaca; background: rgba(127, 29, 29, .35); }
@media (max-width: 640px) {
    .clt-filters, .clt-filter-fields { align-items: stretch; }
    .clt-filter-fields, .clt-filter-fields label, .clt-filter-fields input, .clt-filter-fields select, .clt-filter-fields button { width: 100%; }
}
</style>
