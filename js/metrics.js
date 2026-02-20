// metrics.js - Key Performance Metrics Handler

let metricsData = {};
let metricsChart = null;

document.addEventListener('DOMContentLoaded', function() {
    displayUserInfo();
    setupEventListeners();
    loadSchoolYears();
});

function displayUserInfo() {
    const userData = JSON.parse(localStorage.getItem('user') || '{}');
    const userNameEl = document.getElementById('userName');
    const userInitialsEl = document.getElementById('userInitials');

    if (userData.FirstName && userData.LastName) {
        userNameEl.textContent = `${userData.FirstName} ${userData.LastName}`;
        userInitialsEl.textContent = `${userData.FirstName[0]}${userData.LastName[0]}`.toUpperCase();
    }
}

function setupEventListeners() {
    // Placeholder for future event listeners
}

async function loadSchoolYears() {
    try {
        const response = await fetch('../backend/api/get-school-years.php');
        const data = await response.json();

        const syFilter = document.getElementById('syFilter');
        if (data.success && data.schoolYears && data.schoolYears.length > 0) {
            data.schoolYears.forEach(sy => {
                const option = document.createElement('option');
                option.value = sy;
                option.textContent = `S.Y. ${sy}`;
                syFilter.appendChild(option);
            });
        }

        loadMetrics();
    } catch (error) {
        console.error('Error loading school years:', error);
        loadMetrics();
    }
}

async function loadMetrics() {
    document.getElementById('loadingState').style.display = 'flex';
    document.getElementById('metricsContent').style.display = 'none';

    const sy = document.getElementById('syFilter').value;

    try {
        const response = await fetch(`../backend/api/get-metrics.php?sy=${sy}`);
        const data = await response.json();

        if (data.success) {
            if (data.message) {
                console.info(data.message);
            }

            metricsData = data.data;
            updateMetricsDisplay();
            document.getElementById('loadingState').style.display = 'none';
            document.getElementById('metricsContent').style.display = 'block';

            if (metricsData.ger === 0 && metricsData.promotionRate === 0) {
                showNoDataMessage();
            }
        } else {
            console.error('Error:', data.message);
            document.getElementById('loadingState').style.display = 'none';
            showErrorMessage(data.message, data.debug);
        }
    } catch (error) {
        console.error('Error loading metrics:', error);
        document.getElementById('loadingState').style.display = 'none';
        showErrorMessage('Failed to load metrics data. Please check your database connection.');
    }
}

function showNoDataMessage() {
    const container = document.getElementById('insightsContainer');
    container.innerHTML = `
        <div class="col-span-2 flex items-start gap-3 p-6 bg-blue-50 dark:bg-blue-900/20 rounded-lg border-2 border-blue-200 dark:border-blue-800">
            <span class="material-icons-outlined text-blue-600 text-[32px]">info</span>
            <div>
                <p class="text-base font-semibold text-blue-900 dark:text-blue-100 mb-2">No Enrollment Data Available</p>
                <p class="text-sm text-blue-700 dark:text-blue-200 mb-3">
                    The metrics dashboard requires enrollment data to calculate performance indicators.
                    To see metrics, please ensure:
                </p>
                <ul class="text-sm text-blue-700 dark:text-blue-200 list-disc list-inside space-y-1">
                    <li>Student enrollment records are added to the system</li>
                    <li>Enrollment records have a valid AcademicYear field</li>
                    <li>Student statuses are properly set (Confirmed, Pending, etc.)</li>
                    <li>Grade level assignments are complete</li>
                </ul>
                <p class="text-sm text-blue-700 dark:text-blue-200 mt-3">
                    Once enrollment data is available, refresh this page to view performance metrics.
                </p>
            </div>
        </div>
    `;
}

function showErrorMessage(message, debug = null) {
    const content = document.getElementById('metricsContent');
    content.style.display = 'block';
    content.innerHTML = `
        <div class="flex items-start gap-3 p-6 bg-red-50 dark:bg-red-900/20 rounded-lg border-2 border-red-200 dark:border-red-800">
            <span class="material-icons-outlined text-red-600 text-[32px]">error</span>
            <div class="flex-1">
                <p class="text-base font-semibold text-red-900 dark:text-red-100 mb-2">Error Loading Metrics</p>
                <p class="text-sm text-red-700 dark:text-red-200 mb-3">${message}</p>
                ${debug ? `
                    <details class="text-xs text-red-600 dark:text-red-300 mt-2">
                        <summary class="cursor-pointer font-medium">Technical Details</summary>
                        <pre class="mt-2 p-2 bg-red-100 dark:bg-red-900/40 rounded overflow-auto">${JSON.stringify(debug, null, 2)}</pre>
                    </details>
                ` : ''}
                <div class="mt-4 flex gap-2">
                    <button onclick="location.reload()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm">
                        Retry
                    </button>
                    <a href="dashboard.html" class="px-4 py-2 bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-lg hover:bg-slate-300 text-sm">
                        Return to Dashboard
                    </a>
                </div>
            </div>
        </div>
    `;
}

function updateMetricsDisplay() {
    updateEnrollmentMetrics();
    updateEfficiencyMetrics();
    updateResourceMetrics();
    updateGradeLevelTable();
    updateMetricsChart();
    generateInsights();
}

// ==========================================
// ENROLLMENT METRICS (Tab 1)
// ==========================================
function updateEnrollmentMetrics() {
    // GER — always estimated since we lack PSA population data
    const gerValue = metricsData.ger || 0;
    document.getElementById('gerValue').textContent = gerValue.toFixed(1) + '%';
    if (metricsData.gerIsEstimated) {
        applyEstimatedBadge('gerStatus', gerValue, 95, 85);
    } else {
        updateStatus('gerStatus', gerValue, 95, 85);
    }

    // NER — proportion of age-appropriate students (estimated, not true NER)
    const nerValue = metricsData.ner || 0;
    document.getElementById('nerValue').textContent = nerValue.toFixed(1) + '%';
    if (metricsData.nerIsEstimated) {
        applyEstimatedBadge('nerStatus', nerValue, 90, 80);
    } else {
        updateStatus('nerStatus', nerValue, 90, 80);
    }

    // Transition Rate
    const transitionRate = metricsData.transitionRate || 0;
    document.getElementById('transitionRate').textContent = transitionRate.toFixed(1) + '%';
    if (transitionRate === 0) {
        applyNaBadge('transitionStatus', 'Select a S.Y.');
    } else {
        updateStatus('transitionStatus', transitionRate, 95, 85);
    }
}

// ==========================================
// EFFICIENCY METRICS (Tab 2 cards)
// ==========================================
function updateEfficiencyMetrics() {
    // Promotion Rate
    const promotionRate = metricsData.promotionRate || 0;
    document.getElementById('promotionRate').textContent = promotionRate.toFixed(1) + '%';
    updateStatus('promotionStatus', promotionRate, 95, 85);

    // Cohort Survival Rate (JHS)
    const csrJHS = metricsData.cohortSurvivalRateJHS || 0;
    document.getElementById('csrJHS').textContent = csrJHS.toFixed(1) + '%';
    if (csrJHS === 0) {
        applyNaBadge('csrJHSStatus', 'Need 3+ years data');
    } else {
        updateStatus('csrJHSStatus', csrJHS, 90, 80);
    }

    // Retention Rate
    const retentionRate = metricsData.retentionRate || 0;
    document.getElementById('retentionRate').textContent = retentionRate.toFixed(1) + '%';
    if (retentionRate === 0) {
        applyNaBadge('retentionStatus', 'Select a S.Y.');
    } else {
        updateStatus('retentionStatus', retentionRate, 90, 80);
    }

    // Dropout Rate (lower is better)
    const dropoutRate = metricsData.dropoutRate || 0;
    document.getElementById('dropoutRate').textContent = dropoutRate.toFixed(1) + '%';
    updateStatusReverse('dropoutStatus', dropoutRate, 5, 10);

    // Coefficient of Efficiency (estimated)
    const coe = metricsData.coefficientOfEfficiency || 0;
    document.getElementById('coeValue').textContent = coe.toFixed(1) + '%';
    if (metricsData.coeIsEstimated) {
        applyEstimatedBadge('coeStatus', coe, 90, 80);
    } else {
        updateStatus('coeStatus', coe, 90, 80);
    }

    // Completion Rate (estimated)
    const completionRate = metricsData.completionRate || 0;
    document.getElementById('completionRate').textContent = completionRate.toFixed(1) + '%';
    if (metricsData.completionIsEstimated || completionRate === 0) {
        applyEstimatedBadge('completionStatus', completionRate, 90, 80);
    } else {
        updateStatus('completionStatus', completionRate, 90, 80);
    }
}

// ==========================================
// RESOURCE METRICS (Tab 3)
// ==========================================
function updateResourceMetrics() {
    const str = metricsData.studentTeacherRatio || 0;
    document.getElementById('studentTeacherRatio').textContent = `1:${str.toFixed(0)}`;
    updateStatusReverse('strStatus', str, 35, 45);

    const scr = metricsData.studentClassroomRatio || 0;
    document.getElementById('studentClassroomRatio').textContent = `1:${scr.toFixed(0)}`;
    updateStatusReverse('scrStatus', scr, 40, 50);

    const utilization = metricsData.sectionUtilization || 0;
    document.getElementById('sectionUtilization').textContent = utilization.toFixed(1) + '%';
    updateUtilizationStatus('utilStatus', utilization);
}

// ==========================================
// STATUS BADGE HELPERS
// ==========================================
function updateStatus(elementId, value, goodThreshold, fairThreshold) {
    const element = document.getElementById(elementId);
    if (!element) return;
    element.className = 'text-xs px-2 py-1 rounded-full font-medium';

    if (value >= goodThreshold) {
        element.classList.add('performance-good');
        element.textContent = 'Good';
    } else if (value >= fairThreshold) {
        element.classList.add('performance-fair');
        element.textContent = 'Fair';
    } else {
        element.classList.add('performance-poor');
        element.textContent = 'Needs Improvement';
    }
}

function updateStatusReverse(elementId, value, goodThreshold, fairThreshold) {
    const element = document.getElementById(elementId);
    if (!element) return;
    element.className = 'text-xs px-2 py-1 rounded-full font-medium';

    if (value === 0) {
        element.classList.add('bg-slate-100', 'text-slate-500');
        element.textContent = 'No data';
        return;
    }

    if (value <= goodThreshold) {
        element.classList.add('performance-good');
        element.textContent = 'Good';
    } else if (value <= fairThreshold) {
        element.classList.add('performance-fair');
        element.textContent = 'Fair';
    } else {
        element.classList.add('performance-poor');
        element.textContent = 'Needs Improvement';
    }
}

function updateUtilizationStatus(elementId, value) {
    const element = document.getElementById(elementId);
    if (!element) return;
    element.className = 'text-xs px-2 py-1 rounded-full font-medium';

    if (value === 0) {
        element.classList.add('bg-slate-100', 'text-slate-500');
        element.textContent = 'No data';
        return;
    }

    if (value >= 80 && value <= 95) {
        element.classList.add('performance-good');
        element.textContent = 'Optimal';
    } else if ((value >= 70 && value < 80) || (value > 95 && value <= 100)) {
        element.classList.add('performance-fair');
        element.textContent = 'Fair';
    } else {
        element.classList.add('performance-poor');
        element.textContent = value < 70 ? 'Underutilized' : 'Overcrowded';
    }
}

/** Shows a grey "Estimated" badge with performance coloring */
function applyEstimatedBadge(elementId, value, goodThreshold, fairThreshold) {
    const element = document.getElementById(elementId);
    if (!element) return;

    let perfClass = 'performance-poor';
    if (value >= goodThreshold)       perfClass = 'performance-good';
    else if (value >= fairThreshold)  perfClass = 'performance-fair';

    element.className = `text-xs px-2 py-1 rounded-full font-medium ${perfClass}`;
    element.textContent = 'Est.';
    element.title = 'This metric is estimated. Accurate calculation requires official PSA population data.';
}

/** Shows a neutral "N/A" badge for metrics requiring year-specific data */
function applyNaBadge(elementId, tooltip = '') {
    const element = document.getElementById(elementId);
    if (!element) return;
    element.className = 'text-xs px-2 py-1 rounded-full font-medium bg-slate-100 dark:bg-slate-700 text-slate-500';
    element.textContent = 'N/A';
    if (tooltip) element.title = tooltip;
}

// ==========================================
// GRADE LEVEL TABLE
// ==========================================
function updateGradeLevelTable() {
    const tbody = document.getElementById('gradeLevelTable');
    tbody.innerHTML = '';

    if (metricsData.gradeLevelData && metricsData.gradeLevelData.length > 0) {
        metricsData.gradeLevelData.forEach(grade => {
            const row = document.createElement('tr');
            row.className = 'hover:bg-slate-50 dark:hover:bg-slate-800/50';

            const avgPerformance = (grade.promotionRate + grade.retentionRate) / 2;
            let statusBadge;

            if (avgPerformance >= 90) {
                statusBadge = '<span class="text-xs px-2 py-1 rounded-full performance-good">Excellent</span>';
            } else if (avgPerformance >= 80) {
                statusBadge = '<span class="text-xs px-2 py-1 rounded-full performance-fair">Good</span>';
            } else {
                statusBadge = '<span class="text-xs px-2 py-1 rounded-full performance-poor">At Risk</span>';
            }

            row.innerHTML = `
                <td class="p-3 text-sm text-slate-700 dark:text-slate-300 font-medium">Grade ${grade.gradeLevel}</td>
                <td class="p-3 text-sm text-slate-700 dark:text-slate-300">${grade.enrollment}</td>
                <td class="p-3 text-sm text-slate-700 dark:text-slate-300">${grade.promotionRate.toFixed(1)}%</td>
                <td class="p-3 text-sm text-slate-700 dark:text-slate-300">${grade.retentionRate.toFixed(1)}%</td>
                <td class="p-3 text-sm text-slate-700 dark:text-slate-300">${grade.dropoutRate.toFixed(1)}%</td>
                <td class="p-3 text-sm">${statusBadge}</td>
            `;
            tbody.appendChild(row);
        });
    } else {
        tbody.innerHTML = '<tr><td colspan="6" class="p-3 text-center text-slate-500">No data available</td></tr>';
    }
}

// ==========================================
// TRENDS CHART
// ==========================================
function updateMetricsChart() {
    const ctx = document.getElementById('metricsChart');
    if (!ctx) return;

    if (metricsChart) {
        metricsChart.destroy();
        metricsChart = null;
    }

    if (!metricsData.trends || metricsData.trends.length === 0) {
        return;
    }

    const labels = metricsData.trends.map(t => 'S.Y. ' + t.year);

    metricsChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Promotion Rate',
                    data: metricsData.trends.map(t => t.promotionRate || 0),
                    borderColor: '#10B981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4
                },
                {
                    label: 'Retention Rate',
                    data: metricsData.trends.map(t => t.retentionRate || 0),
                    borderColor: '#8B5CF6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    tension: 0.4
                },
                {
                    label: 'Dropout Rate',
                    data: metricsData.trends.map(t => t.dropoutRate || 0),
                    borderColor: '#EF4444',
                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: true, position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y.toFixed(1) + '%';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) { return value + '%'; }
                    }
                }
            }
        }
    });
}

// ==========================================
// INSIGHTS
// ==========================================
function generateInsights() {
    const container = document.getElementById('insightsContainer');
    container.innerHTML = '';

    const insights = [];
    const sy = document.getElementById('syFilter').value;

    // GER note — always estimated
    insights.push({
        icon: 'info',
        iconColor: 'text-blue-500',
        title: 'GER & NER are Estimated',
        description: 'Gross and Net Enrollment Ratios require official PSA school-age population data for precise calculation. Values shown are internal estimates only.'
    });

    // Dropout insights
    if (metricsData.dropoutRate > 5) {
        insights.push({
            icon: 'error',
            iconColor: 'text-red-600',
            title: 'High School Leaver Rate Alert',
            description: `School leaver rate is at ${metricsData.dropoutRate.toFixed(1)}%, exceeding the 5% threshold. Consider targeted retention and intervention programs.`
        });
    } else if (metricsData.dropoutRate <= 3 && metricsData.dropoutRate > 0) {
        insights.push({
            icon: 'verified',
            iconColor: 'text-green-600',
            title: 'Strong Student Retention',
            description: `Low school leaver rate of ${metricsData.dropoutRate.toFixed(1)}% demonstrates effective student support systems.`
        });
    }

    // Transition rate insights
    if (sy !== 'all') {
        if (metricsData.transitionRate > 0 && metricsData.transitionRate < 85) {
            insights.push({
                icon: 'warning',
                iconColor: 'text-orange-600',
                title: 'JHS to SHS Transition Gap',
                description: `Only ${metricsData.transitionRate.toFixed(1)}% of Grade 10 students continued to Grade 11. Enhanced guidance and SHS promotion programs are recommended.`
            });
        } else if (metricsData.transitionRate >= 95) {
            insights.push({
                icon: 'check_circle',
                iconColor: 'text-green-600',
                title: 'Excellent JHS to SHS Transition',
                description: `${metricsData.transitionRate.toFixed(1)}% transition rate — the vast majority of JHS completers are continuing to SHS.`
            });
        }

        // Retention insight
        if (metricsData.retentionRate > 0 && metricsData.retentionRate < 80) {
            insights.push({
                icon: 'warning',
                iconColor: 'text-orange-600',
                title: 'Below-Average Retention',
                description: `Retention rate of ${metricsData.retentionRate.toFixed(1)}% indicates a notable number of students are not continuing from the previous year.`
            });
        }
    } else {
        insights.push({
            icon: 'info',
            iconColor: 'text-blue-500',
            title: 'Year-Specific Metrics Unavailable',
            description: 'Transition Rate, Retention Rate, and Cohort Survival Rate require a specific school year to be selected. Choose a year in the filter above.'
        });
    }

    // Resource insights
    if (metricsData.studentTeacherRatio > 45) {
        insights.push({
            icon: 'groups',
            iconColor: 'text-red-600',
            title: 'Teacher Shortage Detected',
            description: `Student-teacher ratio of 1:${metricsData.studentTeacherRatio.toFixed(0)} exceeds the DepEd ideal of 1:35. Consider hiring additional teaching staff.`
        });
    } else if (metricsData.studentTeacherRatio > 0 && metricsData.studentTeacherRatio <= 35) {
        insights.push({
            icon: 'school',
            iconColor: 'text-green-600',
            title: 'Teacher Allocation Within Standards',
            description: `Student-teacher ratio of 1:${metricsData.studentTeacherRatio.toFixed(0)} meets DepEd guidelines (target: 1:35 or lower).`
        });
    }

    // Section utilization
    if (metricsData.sectionUtilization > 0 && metricsData.sectionUtilization < 70) {
        insights.push({
            icon: 'info',
            iconColor: 'text-blue-600',
            title: 'Underutilized Sections',
            description: `Section utilization at ${metricsData.sectionUtilization.toFixed(1)}%. Consider consolidating sections to improve resource efficiency.`
        });
    } else if (metricsData.sectionUtilization > 95) {
        insights.push({
            icon: 'warning',
            iconColor: 'text-orange-600',
            title: 'Sections Near Overcapacity',
            description: `Sections are at ${metricsData.sectionUtilization.toFixed(1)}% capacity. Consider opening additional sections to maintain learning quality.`
        });
    }

    // Cohort survival
    if (metricsData.cohortSurvivalRateJHS >= 90 && metricsData.cohortSurvivalRateJHS > 0) {
        insights.push({
            icon: 'emoji_events',
            iconColor: 'text-yellow-600',
            title: 'Strong JHS Cohort Survival',
            description: `${metricsData.cohortSurvivalRateJHS.toFixed(1)}% of the Grade 7 cohort reached Grade 10 — excellent longitudinal retention.`
        });
    }

    if (insights.length === 0) {
        insights.push({
            icon: 'thumb_up',
            iconColor: 'text-primary',
            title: 'Overall Performance is Good',
            description: 'All available key performance indicators are within acceptable ranges. Continue current practices.'
        });
    }

    // Render insights
    insights.forEach(insight => {
        const div = document.createElement('div');
        div.className = 'flex items-start gap-3 p-4 bg-slate-50 dark:bg-slate-700/50 rounded-lg';
        div.innerHTML = `
            <span class="material-icons-outlined ${insight.iconColor} text-[24px]">${insight.icon}</span>
            <div>
                <p class="text-sm font-medium text-slate-900 dark:text-white">${insight.title}</p>
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">${insight.description}</p>
            </div>
        `;
        container.appendChild(div);
    });
}

function resetFilters() {
    document.getElementById('syFilter').value = 'all';
    loadMetrics();
}

function exportMetrics() {
    const sy = document.getElementById('syFilter').value;
    const url = `../backend/api/export-metrics.php?sy=${sy}`;
    window.open(url, '_blank');
}