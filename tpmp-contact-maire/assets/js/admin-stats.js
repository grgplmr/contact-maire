(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function createChart(ctx, config) {
        if (!ctx || !window.Chart) {
            return null;
        }

        return new Chart(ctx, config);
    }

    function buildLineChartConfig(labels, values) {
        return {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Envois',
                        data: values,
                        fill: false,
                        borderColor: '#3366ff',
                        backgroundColor: '#3366ff',
                        tension: 0.2,
                    },
                ],
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 },
                    },
                },
            },
        };
    }

    function buildBarChartConfig(labels, values, horizontal) {
        return {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Total',
                        data: values,
                        backgroundColor: '#47c1bf',
                    },
                ],
            },
            options: {
                indexAxis: horizontal ? 'y' : 'x',
                scales: {
                    x: { beginAtZero: true },
                    y: { beginAtZero: true },
                },
            },
        };
    }

    function buildDoughnutConfig(labels, values) {
        var colors = ['#2ecc71', '#f1c40f', '#e74c3c'];
        return {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [
                    {
                        data: values,
                        backgroundColor: colors,
                    },
                ],
            },
            options: {
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                },
            },
        };
    }

    ready(function () {
        if (typeof TPMP_CONTACT_MAIRE_STATS === 'undefined' || !TPMP_CONTACT_MAIRE_STATS.data) {
            return;
        }

        var data = TPMP_CONTACT_MAIRE_STATS.data;

        createChart(document.getElementById('tpmp-stats-daily'), buildLineChartConfig(data.daily.labels, data.daily.values));
        createChart(
            document.getElementById('tpmp-stats-by-commune'),
            buildBarChartConfig(data.by_commune.labels, data.by_commune.values, true)
        );
        createChart(
            document.getElementById('tpmp-stats-by-category'),
            buildBarChartConfig(data.by_category.labels, data.by_category.values, false)
        );
        createChart(
            document.getElementById('tpmp-stats-by-status'),
            buildDoughnutConfig(data.status.labels, data.status.values)
        );

        var categoriesList = document.getElementById('tpmp-stats-top-categories');
        if (categoriesList && data.top_categories && data.top_categories.length) {
            categoriesList.innerHTML = '';
            data.top_categories.forEach(function (item) {
                var li = document.createElement('li');
                li.innerHTML =
                    '<span class="tpmp-stats-list__label">' +
                    (item.label || 'Sans catégorie') +
                    '</span><span class="tpmp-stats-list__value">' +
                    item.total +
                    '</span>';
                categoriesList.appendChild(li);
            });
        }
    });
})();
