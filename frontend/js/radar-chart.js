/**
 * Radar Chart - OCEAN Visualisierung mit Chart.js
 */

function renderOceanChart(oceanData) {
    console.log('renderOceanChart called with:', oceanData);
    
    const ctx = document.getElementById('ocean-chart');
    console.log('Canvas element:', ctx);
    
    // Destroy existing chart
    if (state.oceanChart) {
        state.oceanChart.destroy();
        console.log('Destroyed existing chart');
    }
    
    // Prepare datasets
    const datasets = [];
    
    // Ist-Profil (immer anzeigen, auch wenn alle Werte 0 sind)
    const hasOceanScores = oceanData && oceanData.ocean_scores;
    datasets.push({
        label: 'Ist-Profil',
        data: [
            hasOceanScores ? (oceanData.ocean_scores.O || 0) : 0,
            hasOceanScores ? (oceanData.ocean_scores.C || 0) : 0,
            hasOceanScores ? (oceanData.ocean_scores.E || 0) : 0,
            hasOceanScores ? (oceanData.ocean_scores.A || 0) : 0,
            hasOceanScores ? (oceanData.ocean_scores.N || 0) : 0
        ],
        backgroundColor: 'rgba(54, 162, 235, 0.2)',
        borderColor: 'rgba(54, 162, 235, 1)',
        borderWidth: 2,
        pointBackgroundColor: 'rgba(54, 162, 235, 1)',
        pointBorderColor: '#fff',
        pointHoverBackgroundColor: '#fff',
        pointHoverBorderColor: 'rgba(54, 162, 235, 1)',
        pointRadius: 5,
        pointHoverRadius: 7
    });
    
    // Ideal-Profil (falls vorhanden)
    if (oceanData.profiles && oceanData.profiles.ideal) {
        const ideal = oceanData.profiles.ideal;
        datasets.push({
            label: 'Ideal-Profil',
            data: [
                ideal.O || 0,
                ideal.C || 0,
                ideal.E || 0,
                ideal.A || 0,
                ideal.N || 0
            ],
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            borderColor: 'rgba(75, 192, 192, 1)',
            borderWidth: 2,
            borderDash: [5, 5],
            pointBackgroundColor: 'rgba(75, 192, 192, 1)',
            pointBorderColor: '#fff',
            pointHoverBackgroundColor: '#fff',
            pointHoverBorderColor: 'rgba(75, 192, 192, 1)',
            pointRadius: 5,
            pointHoverRadius: 7
        });
    }
    
    // Halter-Profil (falls vorhanden)
    if (oceanData.profiles && oceanData.profiles.owner) {
        const owner = oceanData.profiles.owner;
        datasets.push({
            label: 'Halter-Profil',
            data: [
                owner.O || 0,
                owner.C || 0,
                owner.E || 0,
                owner.A || 0,
                owner.N || 0
            ],
            backgroundColor: 'rgba(255, 159, 64, 0.2)',
            borderColor: 'rgba(255, 159, 64, 1)',
            borderWidth: 2,
            borderDash: [10, 5],
            pointBackgroundColor: 'rgba(255, 159, 64, 1)',
            pointBorderColor: '#fff',
            pointHoverBackgroundColor: '#fff',
            pointHoverBorderColor: 'rgba(255, 159, 64, 1)',
            pointRadius: 5,
            pointHoverRadius: 7
        });
    }
    
    // Chart erstellen (auch wenn datasets leer ist, für leeres Diagramm)
    console.log('Creating chart with datasets:', datasets);
    
    state.oceanChart = new Chart(ctx, {
        type: 'radar',
        data: {
            labels: [
                'Openness\n(Offenheit)',
                'Conscientiousness\n(Gewissenhaftigkeit)',
                'Extraversion\n(Extraversion)',
                'Agreeableness\n(Verträglichkeit)',
                'Neuroticism\n(Neurotizismus)'
            ],
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 1.5,
            scales: {
                r: {
                    angleLines: {
                        display: true,
                        color: 'rgba(0, 0, 0, 0.1)'
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.1)'
                    },
                    pointLabels: {
                        font: {
                            size: 12,
                            weight: 'bold'
                        },
                        color: '#333'
                    },
                    ticks: {
                        stepSize: 5,
                        backdropColor: 'rgba(255, 255, 255, 0.8)'
                    },
                    suggestedMin: -14,
                    suggestedMax: 14
                }
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        font: {
                            size: 14
                        },
                        padding: 15,
                        usePointStyle: true
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            label += context.parsed.r.toFixed(1);
                            return label;
                        }
                    }
                }
            },
            interaction: {
                mode: 'point',
                intersect: true
            }
        }
    });
    
    console.log('Chart created successfully:', state.oceanChart);
}

/**
 * OCEAN Chart im Modal anzeigen (Vollbild)
 */
function showOceanChartModal() {
    // Hole aktuelle Ocean-Daten
    if (!state.currentSession || !state.currentSession.ocean_scores) {
        showToast('Keine OCEAN-Daten verfügbar', 'warning');
        return;
    }

    // Modal öffnen
    const modal = new bootstrap.Modal(document.getElementById('oceanChartModal'));
    modal.show();

    // Warten bis Modal vollständig geöffnet ist, dann Chart rendern
    setTimeout(() => {
        renderOceanChartModal(state.currentSession);
    }, 300);
}

/**
 * OCEAN Chart im Modal rendern
 */
function renderOceanChartModal(oceanData) {
    const ctx = document.getElementById('ocean-chart-modal');
    
    // Destroy existing modal chart
    if (state.oceanChartModal) {
        state.oceanChartModal.destroy();
    }
    
    // Prepare datasets
    const datasets = [];
    
    // Ist-Profil (immer vorhanden)
    if (oceanData.ocean_scores) {
        datasets.push({
            label: 'Ist-Profil',
            data: [
                oceanData.ocean_scores.O || 0,
                oceanData.ocean_scores.C || 0,
                oceanData.ocean_scores.E || 0,
                oceanData.ocean_scores.A || 0,
                oceanData.ocean_scores.N || 0
            ],
            backgroundColor: 'rgba(54, 162, 235, 0.2)',
            borderColor: 'rgba(54, 162, 235, 1)',
            borderWidth: 3,
            pointBackgroundColor: 'rgba(54, 162, 235, 1)',
            pointBorderColor: '#fff',
            pointHoverBackgroundColor: '#fff',
            pointHoverBorderColor: 'rgba(54, 162, 235, 1)',
            pointRadius: 8,
            pointHoverRadius: 10
        });
    }
    
    // Ideal-Profil (falls vorhanden)
    if (oceanData.profiles && oceanData.profiles.ideal) {
        const ideal = oceanData.profiles.ideal;
        datasets.push({
            label: 'Ideal-Profil',
            data: [
                ideal.O || 0,
                ideal.C || 0,
                ideal.E || 0,
                ideal.A || 0,
                ideal.N || 0
            ],
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            borderColor: 'rgba(75, 192, 192, 1)',
            borderWidth: 3,
            borderDash: [5, 5],
            pointBackgroundColor: 'rgba(75, 192, 192, 1)',
            pointBorderColor: '#fff',
            pointHoverBackgroundColor: '#fff',
            pointHoverBorderColor: 'rgba(75, 192, 192, 1)',
            pointRadius: 8,
            pointHoverRadius: 10
        });
    }
    
    // Halter-Profil (falls vorhanden)
    if (oceanData.profiles && oceanData.profiles.owner) {
        const owner = oceanData.profiles.owner;
        datasets.push({
            label: 'Halter-Profil',
            data: [
                owner.O || 0,
                owner.C || 0,
                owner.E || 0,
                owner.A || 0,
                owner.N || 0
            ],
            backgroundColor: 'rgba(255, 159, 64, 0.2)',
            borderColor: 'rgba(255, 159, 64, 1)',
            borderWidth: 3,
            borderDash: [10, 5],
            pointBackgroundColor: 'rgba(255, 159, 64, 1)',
            pointBorderColor: '#fff',
            pointHoverBackgroundColor: '#fff',
            pointHoverBorderColor: 'rgba(255, 159, 64, 1)',
            pointRadius: 8,
            pointHoverRadius: 10
        });
    }
    
    // Chart erstellen
    state.oceanChartModal = new Chart(ctx, {
        type: 'radar',
        data: {
            labels: [
                'Openness\n(Offenheit)',
                'Conscientiousness\n(Gewissenhaftigkeit)',
                'Extraversion\n(Extraversion)',
                'Agreeableness\n(Verträglichkeit)',
                'Neuroticism\n(Neurotizismus)'
            ],
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 1.2,
            scales: {
                r: {
                    angleLines: {
                        display: true,
                        color: 'rgba(0, 0, 0, 0.1)',
                        lineWidth: 2
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.1)',
                        lineWidth: 2
                    },
                    pointLabels: {
                        font: {
                            size: 18,
                            weight: 'bold'
                        },
                        color: '#333'
                    },
                    ticks: {
                        stepSize: 5,
                        backdropColor: 'rgba(255, 255, 255, 0.8)',
                        font: {
                            size: 14
                        }
                    },
                    suggestedMin: -14,
                    suggestedMax: 14
                }
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        font: {
                            size: 18
                        },
                        padding: 20,
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            label += context.parsed.r.toFixed(1);
                            return label;
                        }
                    },
                    titleFont: {
                        size: 16
                    },
                    bodyFont: {
                        size: 14
                    }
                }
            },
            interaction: {
                mode: 'point',
                intersect: true
            }
        }
    });
}

/**
 * Update nur ein Dataset (z.B. nach Änderung Halter-Profil)
 */
function updateOceanChartDataset(label, data) {
    if (!state.oceanChart) return;
    
    const dataset = state.oceanChart.data.datasets.find(ds => ds.label === label);
    
    if (dataset) {
        dataset.data = [data.O, data.C, data.E, data.A, data.N];
        state.oceanChart.update();
    }
}

/**
 * Chart leeren
 */
function clearOceanChart() {
    if (state.oceanChart) {
        state.oceanChart.destroy();
        state.oceanChart = null;
    }
    if (state.oceanChartModal) {
        state.oceanChartModal.destroy();
        state.oceanChartModal = null;
    }
}

