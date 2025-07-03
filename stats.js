
document.addEventListener('DOMContentLoaded', function() {
    const dateRangeInput = document.querySelector('.date-range');
    const startDateInput = document.querySelector('input[name="start_date"]');
    const endDateInput = document.querySelector('input[name="end_date"]');

    if (dateRangeInput) {
        flatpickr(dateRangeInput, {
            mode: 'range',
            dateFormat: 'Y-m-d',
            defaultDate: [startDateInput.value, endDateInput.value],
            onChange(selectedDates) {
                if (selectedDates.length === 2) {
                    startDateInput.value = selectedDates[0] ? selectedDates[0].toISOString().split('T')[0] : '';
                    endDateInput.value = selectedDates[1] ? selectedDates[1].toISOString().split('T')[0] : '';
                }
            }
        });
    }

    const dataTag = document.getElementById('stats-data');
    const stats = dataTag ? JSON.parse(dataTag.textContent) : {};

    const activityCtx = document.getElementById('activityChart')?.getContext('2d');
    if (activityCtx && stats.activityDates) {
        new Chart(activityCtx, {
            type: 'line',
            data: {
                labels: stats.activityDates,
                datasets: [
                    {
                        label: 'Подключения',
                        data: stats.activityConnections,
                        backgroundColor: 'rgba(54, 162, 235, 0.1)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: 'rgba(54, 162, 235, 1)',
                        tension: 0.4,
                        fill: true,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Время игры (часы)',
                        data: stats.activityPlaytime,
                        backgroundColor: 'rgba(255, 159, 64, 0.1)',
                        borderColor: 'rgba(255, 159, 64, 1)',
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: 'rgba(255, 159, 64, 1)',
                        tension: 0.4,
                        fill: true,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0, 0, 0, 0.05)' },
                        ticks: { color: 'rgba(0, 0, 0, 0.7)' },
                        position: 'left',
                        title: { display: true, text: 'Подключения' }
                    },
                    y1: {
                        beginAtZero: true,
                        grid: { drawOnChartArea: false },
                        ticks: { color: 'rgba(255, 159, 64, 1)' },
                        position: 'right',
                        title: { display: true, text: 'Часы игры' }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: 'rgba(116, 118, 121, 0.72)' }
                    }
                }
            }
        });
    }

    if (stats.selectedTab === 'additional') {
        const mapsCtx = document.getElementById('mapsChart')?.getContext('2d');
        if (mapsCtx) {
            new Chart(mapsCtx, {
                type: 'bar',
                data: {
                    labels: stats.maps,
                    datasets: [{
                        label: 'Время игры (часы)',
                        data: stats.mapsPlaytime,
                        backgroundColor: 'rgba(75, 192, 192, 0.6)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Часы игры' }
                        }
                    }
                }
            });
        }

        const countriesCtx = document.getElementById('countriesChart')?.getContext('2d');
        if (countriesCtx) {
            new Chart(countriesCtx, {
                type: 'doughnut',
                data: {
                    labels: stats.countries,
                    datasets: [{
                        label: 'Подключения',
                        data: stats.countriesConnections,
                        backgroundColor: [
                            'rgba(255, 99, 132, 0.6)',
                            'rgba(54, 162, 235, 0.6)',
                            'rgba(255, 206, 86, 0.6)',
                            'rgba(75, 192, 192, 0.6)',
                            'rgba(153, 102, 255, 0.6)',
                            'rgba(255, 159, 64, 0.6)',
                            'rgba(199, 199, 199, 0.6)',
                            'rgba(83, 102, 255, 0.6)',
                            'rgba(40, 159, 64, 0.6)',
                            'rgba(210, 99, 132, 0.6)'
                        ],
                        borderColor: [
                            'rgba(255, 99, 132, 1)',
                            'rgba(54, 162, 235, 1)',
                            'rgba(255, 206, 86, 1)',
                            'rgba(75, 192, 192, 1)',
                            'rgba(153, 102, 255, 1)',
                            'rgba(255, 159, 64, 1)',
                            'rgba(199, 199, 199, 1)',
                            'rgba(83, 102, 255, 1)',
                            'rgba(40, 159, 64, 1)',
                            'rgba(210, 99, 132, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'right' } }
                }
            });
        }
    }
});

