<template>
    <div>
        <div class="card p-2 content">
            <div class="flex mb-2">
                <label class="mr-4">
                    From: <input class="border p-1 rounded"
                                 type="date"
                                 :disabled="fetching"
                                 v-model="date_start"
                                 @change="fetchData"
                                 :max="date_end">
                </label>

                <label>
                    Until: <input class="border p-1 rounded"
                                  type="date"
                                  :disabled="fetching"
                                  v-model="date_end"
                                  @change="fetchData"
                                  :min="date_start">
                </label>
            </div>
            <div id="chart" class="w-full"></div>
        </div>
        <!-- Button Row to toggle the Lines -->
        <div class="flex justify-between mt-2">
            <div v-for="(line, index) in lines" :key="index"
                 :style="`border-color: ${line.color}; width: 16%`"
                 :class="['cursor-pointer p-2', {'border-2': ui[index]}]"
                 @click="toggleLine(index)">
                {{ line.title }}<br>
                {{ sums[index] }}
            </div>
        </div>
    </div>
</template>

<script>

function Chart() {}
Chart.prototype = {
    colors: ['#d2007a', '#0098d4', '#dddb2f', '#1d7e07', '#2c4a4a', '#ff5928'],
    createSvgElement: function(tag, attributes) {
        var element = document.createElementNS('http://www.w3.org/2000/svg', tag);
        for(var key in attributes) {
            element.setAttribute(key, attributes[key]);
        }
        return element;
    },
    formatNumber: function(number) {
        var string = '';

        number = number + '';
        while(number.length > 3) {
            string = '.' + number.substr(number.length - 3) + string;
            number = number.substr(0, number.length - 3);
        }
        string = number + string;

        return string;
    }
};
function LineChart(container, data) {
    Chart.call(this);

    var chart = this;

    chart.container = container;
    chart.labels = data.labels;
    chart.lines = data.lines;
    chart.tooltip = null;

    chart.svgElement = chart.createSvgElement('svg', {
        style: 'font-family: Arial, sans-serif;'
    });
    chart.container.appendChild(chart.svgElement);

    window.addEventListener('resize', function() {
        chart.render();
    });

    chart.svgElement.addEventListener('mouseleave', function() {
        chart.removeTooltip();
    });

    chart.svgElement.addEventListener('mousemove', function(event) {
        const chartBounds = chart.svgElement.getBoundingClientRect();
        const bounds = {
            top: chartBounds.top + document.body.scrollTop,
            left: chartBounds.left + document.body.scrollLeft,
        };

        const mouse = {
            x: event.pageX - bounds.left,
            y: event.pageY - bounds.top
        };

        var datumIndex = Math.max(0, Math.min(chart.labels.length - 1, Math.round((mouse.x - chart.padding.left - 20) / chart.axis.x.stepSize)));

        if (chart.tooltip === null || chart.tooltip.datumIndex !== datumIndex) {
            chart.removeTooltip();

            chart.tooltip = {
                datumIndex: datumIndex,
                element: chart.createSvgElement('g', {
                    'pointer-events': 'none'
                }),
                line: chart.createSvgElement('line', {
                    x1: chart.padding.left + 20 + datumIndex * chart.axis.x.stepSize,
                    y1: chart.padding.top,
                    x2: chart.padding.left + 20 + datumIndex * chart.axis.x.stepSize,
                    y2: chart.height - chart.padding.bottom,
                    stroke: '#c0c0c0'
                })
            };

            chart.svgElement.insertBefore(chart.tooltip.line, chart.svgElement.firstChild);
            chart.svgElement.appendChild(chart.tooltip.element);

            var textElement = chart.createSvgElement('text', {
                x: 10,
                y: 21,
                'font-size': 12
            });
            textElement.textContent = chart.labels[datumIndex];
            chart.tooltip.element.appendChild(textElement);

            for(var lineIndex = 0; lineIndex < chart.lines.length; lineIndex++) {
                var textElement = chart.createSvgElement('text', {
                    x: 10,
                    y: 21 + (lineIndex + 1) * 16,
                    'font-size': 12
                });
                chart.tooltip.element.appendChild(textElement);

                var titleElement = chart.createSvgElement('tspan', {
                    fill: chart.colors[lineIndex % chart.colors.length]
                });
                textElement.appendChild(titleElement);
                titleElement.textContent = chart.lines[lineIndex].title + ': ';

                var valueElement = chart.createSvgElement('tspan', {
                    'font-weight': 'bold'
                });
                textElement.appendChild(valueElement);
                valueElement.textContent = chart.formatNumber(chart.lines[lineIndex].data[datumIndex]);
            }

            var box = chart.tooltip.element.getBBox();
            var boxElement = chart.createSvgElement('rect', {
                x: 0,
                y: 0,
                width: box.width + 20,
                height: box.height + 20,
                rx: 3,
                ry: 3,
                fill: 'rgba(255, 255, 255, .9)',
                stroke: '#c0c0c0'
            });
            chart.tooltip.element.insertBefore(boxElement, chart.tooltip.element.firstChild);

            var left = chart.padding.left + 20 + datumIndex * chart.axis.x.stepSize - box.width - 30;
            var top = mouse.y - (box.height + 20) / 2;

            if(left < 0) {
                left += box.width + 40;
            }
            top = Math.max(chart.padding.top, Math.min(300 - chart.padding.bottom - box.height - 20, top));

            chart.tooltip.element.setAttribute('transform', 'translate(' + left + ', ' + top + ')');
        }

    });

    chart.render();
}
LineChart.prototype = Object.create(Chart.prototype, {
    constructor: {
        value: LineChart
    },
    render: {
        value: function() {
            var chart = this;

            chart.width = chart.container.clientWidth;
            chart.height = 300;

            chart.svgElement.setAttribute('width', chart.width);
            chart.svgElement.setAttribute('height', chart.height);
            chart.svgElement.setAttribute('viewBox', '0 0 ' + chart.width + ' ' + chart.height);

            while(chart.svgElement.childNodes.length) {
                chart.svgElement.removeChild(chart.svgElement.firstChild);
            }

            chart.renderLegend();
            chart.renderAxis();
            chart.renderLines();
        }
    },
    renderLegend: {
        value: function() {
            var chart = this;

            chart.legend = chart.createSvgElement('g');
            chart.svgElement.appendChild(chart.legend);

            var offset = 0;
            for(var index = 0; index < chart.lines.length; index++) {
                var legendItemElement = chart.createSvgElement('g', {
                    transform: 'translate(' + offset + ', 0)'
                });
                chart.legend.appendChild(legendItemElement);

                var circleElement = chart.createSvgElement('circle', {
                    cx: 10,
                    cy: 8,
                    r: 3.5,
                    fill: chart.lines[index].color,
                    stroke: 'none'
                });
                legendItemElement.appendChild(circleElement);

                var textElement = chart.createSvgElement('text', {
                    x: 20,
                    y: 13,
                    'font-size': 14
                });
                legendItemElement.appendChild(textElement);

                textElement.textContent = chart.lines[index].title;

                offset += legendItemElement.getBBox().width + 20;
            }
        }
    },
    renderAxis: {
        value: function() {
            var chart = this;

            chart.axis = {
                x: {
                    nthLabel: 1
                },
                y: {
                    maximum: 1,
                    step: 1
                }
            };

            for(var lineIndex = 0; lineIndex < chart.lines.length; lineIndex++) {
                for(var datumIndex = 0; datumIndex < chart.lines[lineIndex].data.length; datumIndex++) {
                    chart.axis.y.maximum = Math.max(chart.axis.y.maximum, chart.lines[lineIndex].data[datumIndex]);
                }
            }

            while(chart.axis.y.step * 7 < chart.axis.y.maximum) {
                chart.axis.y.step *= 10;
            }
            if(chart.axis.y.step % 2 === 0 && chart.axis.y.step * 3.5 > chart.axis.y.maximum) {
                chart.axis.y.step /= 2;
            }

            chart.axis.y.maximum = Math.ceil(chart.axis.y.maximum / chart.axis.y.step) * chart.axis.y.step;

            chart.axis.x.element = chart.createSvgElement('g');
            chart.svgElement.appendChild(chart.axis.x.element);

            chart.axis.y.element = chart.createSvgElement('g');
            chart.svgElement.appendChild(chart.axis.y.element);

            for(var index = 0; index < chart.labels.length; index++) {
                chart.axis.x.element.appendChild(chart.renderXAxisLabel(chart.labels[index]));
            }

            for(var y = 0; y <= chart.axis.y.maximum; y += chart.axis.y.step) {
                chart.axis.y.element.appendChild(chart.renderYAxisLabel(chart.formatNumber(y)));
            }

            chart.padding = {
                top: chart.legend.getBBox().height + 20,
                left: -1 * Math.min(chart.axis.x.element.getBBox().x + 20, chart.axis.y.element.getBBox().x - 8),
                bottom: chart.axis.x.element.getBBox().height
            };

            chart.axis.x.element.setAttribute('transform', 'translate(0, ' + (chart.height - chart.padding.bottom) + ')');
            chart.axis.x.stepSize = chart.labels.length > 1 ? (chart.width - chart.padding.left - 40) / (chart.labels.length - 1) : 20;

            let rounds = 0;
            while(chart.axis.x.stepSize * chart.axis.x.nthLabel < 20) {
                chart.axis.x.nthLabel++;
                if (rounds > 40) break;
                rounds++;
            }

            if(chart.axis.x.nthLabel > 1) {
                for(var index = chart.axis.x.element.childNodes.length - 1; index > 0; index--) {
                    if(index % chart.axis.x.nthLabel) {
                        chart.axis.x.element.removeChild(chart.axis.x.element.childNodes[index]);
                    }
                }
            }

            for(var index = 0; index < chart.axis.x.element.childNodes.length; index++) {
                chart.axis.x.element.childNodes[index].setAttribute('transform', 'translate(' + (chart.padding.left + 20 + chart.axis.x.nthLabel * index * chart.axis.x.stepSize) + ', 0)');
            }

            chart.axis.y.element.setAttribute('transform', 'translate(' + chart.padding.left + ', 0)');
            chart.axis.y.stepSize = (300 - chart.padding.bottom - chart.padding.top) / (chart.axis.y.maximum / chart.axis.y.step);
            for(var index = 0; index < chart.axis.y.element.childNodes.length; index++) {
                chart.axis.y.element.childNodes[index].setAttribute('transform', 'translate(0, ' + (300 - chart.padding.bottom - index * chart.axis.y.stepSize) + ')');
            }
        }
    },
    renderXAxisLabel: {
        value: function(label) {
            var chart = this;

            var labelElement = chart.createSvgElement('g');

            var lineElement = chart.createSvgElement('line', {
                x1: 0,
                y1: 0,
                x2: 0,
                y2: 5,
                stroke: '#c0c0c0'
            });
            labelElement.appendChild(lineElement);

            var textElement = chart.createSvgElement('text', {
                x: -15,
                y: 7,
                'font-size': 11,
                'text-anchor': 'end',
                transform: 'rotate(-45 0 0)'
            });
            textElement.textContent = label;
            labelElement.appendChild(textElement);

            return labelElement;
        }
    },
    renderYAxisLabel: {
        value: function(label) {
            var chart = this;

            var labelElement = chart.createSvgElement('g');

            var lineElement = chart.createSvgElement('line', {
                x1: 0,
                y1: 0,
                x2: chart.width,
                y2: 0,
                stroke: '#c0c0c0'
            });
            labelElement.appendChild(lineElement);

            var textElement = chart.createSvgElement('text', {
                x: -8,
                y: 4,
                'font-size': 11,
                'text-anchor': 'end'
            });
            textElement.textContent = label;
            labelElement.appendChild(textElement);

            return labelElement;
        }
    },
    renderLines: {
        value: function() {
            var chart = this;

            for(var lineIndex = 0; lineIndex < chart.lines.length; lineIndex++) {
                chart.lines[lineIndex].element = chart.createSvgElement('g', {
                    transform: 'translate(' + (chart.padding.left + 20) + ', ' + (300 - chart.padding.bottom) + ')'
                });
                chart.svgElement.appendChild(chart.lines[lineIndex].element);

                var points = [];
                chart.lines[lineIndex].circles = [];

                for(var datumIndex = 0; datumIndex < chart.lines[lineIndex].data.length; datumIndex++) {
                    var point = {
                        x: datumIndex * chart.axis.x.stepSize,
                        y: -1 * chart.lines[lineIndex].data[datumIndex] * (chart.axis.y.stepSize / chart.axis.y.step),
                    };
                    points.push(point.x + ',' + point.y);

                    if(chart.axis.x.stepSize >= 10) {
                        var circleElement = chart.createSvgElement('circle', {
                            cx: point.x,
                            cy: point.y,
                            r: 3.5,
                            fill: chart.lines[lineIndex].color,
                            stroke: 'none',
                            cursor: 'pointer'
                        });
                        chart.lines[lineIndex].element.appendChild(circleElement);
                        chart.lines[lineIndex].circles.push(circleElement);

                        circleElement.addEventListener('mouseenter', function(event) {
                            event.target.setAttribute('r', 4.5);
                        });
                        circleElement.addEventListener('mouseleave', function(event) {
                            event.target.setAttribute('r', 3.5);
                        });
                        // circleElement.addEventListener('click', function(event) {
                        //     alert('Du hast auf einen Punkt geklickt.');
                        // });
                    }
                }

                var lineElement = chart.createSvgElement('polyline', {
                    points: points.join(' '),
                    stroke: chart.lines[lineIndex].color,
                    'stroke-width': 2,
                    fill: 'none'
                });
                chart.lines[lineIndex].element.insertBefore(lineElement, chart.lines[lineIndex].element.firstChild);
                chart.lines[lineIndex].line = lineElement;
            }
        }
    },
    removeTooltip: {
        value: function() {
            var chart = this;

            if(chart.tooltip !== null) {
                chart.svgElement.removeChild(chart.tooltip.element);
                chart.svgElement.removeChild(chart.tooltip.line);
                chart.tooltip = null;
            }
        }
    }
});

export default {
    name: "RC_Cookieless_Tracking_Main",
    props: ['dbStats', 'fetchUrl'],
    data() {
        return {
            message: "Test 2?",
            rawStats: this.dbStats,
            ui: {
                sessions: true,
                views: true,
                downloads: false,
                conversions: false,
                submits: false,
                bounces: false,
            },
            lines: {},
            sums: {},

            date_start: null,
            date_end: null,
            fetching: false,
        }
    },
    computed: {

    },
    methods: {
        fetchData() {
            if (this.fetching) return;
            this.fetching = true;

            const request = new Request(`${this.fetchUrl}?start=${this.date_start}&end=${this.date_end}`);
            fetch(request)
                .then((response) => response.json())
                .then((data) => {
                    this.rawStats = data;

                    this.parseStats();
                    this.renderChart();
                })
                .finally(() => {
                    this.fetching = false;
                });
        },
        toggleLine(line) {
            this.ui[line] = !this.ui[line];
            this.renderChart();
        },
        reset() {
            this.lines = {
                sessions: {title: 'User Sessions', data: [], color: '#d2007a'},
                views: {title: 'Views', data: [], color: '#0098d4'},
                downloads: {title: 'Downloads', data: [], color: '#dddb2f'},
                conversions: {title: 'Conversions', data: [], color: '#1d7e07'},
                submits: {title: 'Submits', data: [], color: '#599696'},
                bounces: {title: 'Bounces', data: [], color: '#ff5928'},
            };

            // Reset Sums
            this.sums = {
                sessions: 0,
                views: 0,
                downloads: 0,
                conversions: 0,
                submits: 0,
                bounces: 0,
            };
        },
        parseStats() {
            this.reset();

            this.date_start = this.rawStats[0].label;
            this.date_end = this.rawStats[this.rawStats.length - 1].label;

            this.rawStats.forEach(stat => {
                this.lines.sessions.data.push(stat.sessions);
                this.lines.views.data.push(stat.views);
                this.lines.downloads.data.push(stat.downloads);
                this.lines.conversions.data.push(stat.conversions);
                this.lines.submits.data.push(stat.submits);
                this.lines.bounces.data.push(stat.bounces);

                this.sums.sessions += stat.sessions;
                this.sums.views += stat.views;
                this.sums.downloads += stat.downloads;
                this.sums.conversions += stat.conversions;
                this.sums.submits += stat.submits;
                this.sums.bounces += stat.bounces;
            });
        },
        renderChart() {
            let data = {
                labels: this.rawStats.map(e => e.label),
                lines: [],
            };

            for (let line in this.ui) {
                if (this.ui[line]) {
                    data.lines.push(this.lines[line]);
                }
            }

            document.getElementById('chart').innerHTML = '';
            new LineChart(document.getElementById('chart'), data);
        }
    },
    mounted() {
        this.parseStats();
        this.renderChart();
    }
}
</script>

<style scoped>

</style>
