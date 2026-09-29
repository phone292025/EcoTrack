/**
 * EcoTrack — Chart Helpers
 * File: assets/js/charts.js
 *
 * Usage: list 'charts.js' in $pageScripts on pages that need charts. PHP
 * hands the data over in JSON blocks, which are data, not code, so they
 * work under the Content Security Policy:
 *
 *   <script type="application/json" id="categoryData"><?= jsonForHtml(getCategoryBreakdown($uid)) ?></script>
 *   <script type="application/json" id="co2Data"><?= jsonForHtml(getCO2Savings($uid)) ?></script>
 *
 * The goal progress bar reads its percentage from data-percent.
 */

'use strict';

/* ═══════════════════════════════════════════════════════════
 *  CATEGORY BREAKDOWN DONUT CHART
 *  Canvas element: <canvas id="categoryChart"></canvas>
 * ═══════════════════════════════════════════════════════════ */
function initCategoryChart(data) {
  const canvas = document.getElementById('categoryChart');
  if (!canvas || typeof Chart === 'undefined') return;

  // Show "No data yet" message if all zeros
  const total = data.data.reduce((a, b) => a + b, 0);
  if (total === 0) {
    const parent = canvas.parentElement;
    canvas.style.display = 'none';
    const msg = document.createElement('p');
    msg.className   = 'chart-empty';
    msg.textContent = 'Log your first activity to see your category breakdown!';
    parent.appendChild(msg);
    return;
  }

  new Chart(canvas, {
    type: 'doughnut',
    data: {
      labels  : data.labels,
      datasets: [{
        data           : data.data,
        backgroundColor: data.colors,
        borderWidth    : 2,
        borderColor    : '#ffffff',
        hoverOffset    : 8,
      }],
    },
    options: {
      responsive         : true,
      maintainAspectRatio: true,
      cutout             : '62%',
      plugins: {
        legend: {
          position: 'bottom',
          labels  : {
            padding    : 16,
            font       : { size: 13, family: "'Segoe UI', sans-serif" },
            usePointStyle: true,
          },
        },
        tooltip: {
          callbacks: {
            label: (ctx) => {
              const pct = ((ctx.parsed / total) * 100).toFixed(1);
              return ` ${ctx.label}: ${ctx.parsed} pts (${pct}%)`;
            },
          },
        },
      },
      animation: {
        animateRotate : true,
        animateScale  : true,
        duration      : 800,
        easing        : 'easeInOutQuart',
      },
    },
  });
}

/* ═══════════════════════════════════════════════════════════
 *  CO2 SAVINGS LINE CHART
 *  Canvas element: <canvas id="co2Chart"></canvas>
 * ═══════════════════════════════════════════════════════════ */
function initCO2Chart(data) {
  const canvas = document.getElementById('co2Chart');
  if (!canvas || typeof Chart === 'undefined') return;
  const isMobile = window.matchMedia('(max-width: 768px)').matches;

  if (!data.labels || data.labels.length === 0) {
    const msg = document.createElement('p');
    msg.className   = 'chart-empty';
    msg.textContent = 'Your CO₂ savings graph will appear after your first approved activity.';
    canvas.parentElement.replaceChild(msg, canvas);
    return;
  }

  new Chart(canvas, {
    type: 'line',
    data: {
      labels  : data.labels,
      datasets: [{
        label          : 'Cumulative CO₂ Saved (kg)',
        data           : data.data,
        borderColor    : '#2d936c',
        backgroundColor: 'rgba(45,147,108,0.12)',
        borderWidth    : 2.5,
        pointBackgroundColor: '#2d936c',
        pointRadius    : 4,
        pointHoverRadius: 7,
        fill           : true,
        tension        : 0.35,
      }],
    },
    options: {
      responsive         : true,
      maintainAspectRatio: true,
      plugins: {
        legend: {
          position: 'top',
          labels: {
            boxWidth: isMobile ? 28 : 40,
            boxHeight: isMobile ? 10 : 12,
            padding: isMobile ? 10 : 16,
            font: { size: isMobile ? 11 : 12 },
          },
        },
        tooltip: {
          callbacks: {
            label: (ctx) => ` ${ctx.parsed.y.toFixed(3)} kg CO₂ saved`,
          },
        },
      },
      scales: {
        x: {
          grid : { display: false },
          ticks: {
            maxTicksLimit: isMobile ? 4 : 8,
            maxRotation: isMobile ? 0 : 45,
            minRotation: 0,
            font: { size: isMobile ? 10 : 12 },
          },
        },
        y: {
          beginAtZero: true,
          ticks: {
            maxTicksLimit: isMobile ? 5 : 8,
            font: { size: isMobile ? 10 : 12 },
            callback: (v) => v.toFixed(2) + ' kg',
          },
          grid: { color: 'rgba(0,0,0,0.06)' },
        },
      },
      animation: { duration: 1000, easing: 'easeInOutQuart' },
    },
  });
}

/* ═══════════════════════════════════════════════════════════
 *  POINTS GOAL PROGRESS BAR  (not a Chart.js chart — pure CSS)
 *  Updates the inline progress bar on the dashboard.
 * ═══════════════════════════════════════════════════════════ */
function initGoalProgressBar(percent) {
  const bar = document.getElementById('goalProgressBar');
  if (!bar) return;

  percent = Math.min(100, Math.max(0, percent));
  bar.style.width = percent + '%';

  const track = bar.closest('.progress-bar');
  if (track) track.setAttribute('aria-valuenow', percent);

  // Colour-coded: red < 33%, amber < 66%, green >= 66%
  bar.className = 'progress-fill';
  if (percent >= 66)      bar.classList.add('progress-fill--green');
  else if (percent >= 33) bar.classList.add('progress-fill--amber');
  else                    bar.classList.add('progress-fill--red');

  // The label is rendered server-side with the full "45 / 100 points - 45%
  // complete" text. Overwriting it here would throw that detail away.
}

/* ═══════════════════════════════════════════════════════════
 *  AUTO-INIT on DOMContentLoaded
 * ═══════════════════════════════════════════════════════════ */
/**
 * If Chart.js failed to load, say so in place of the canvas instead of
 * leaving a silent blank box.
 */
function reportMissingChartLibrary() {
  document.querySelectorAll('#categoryChart, #co2Chart').forEach(canvas => {
    const msg = document.createElement('p');
    msg.className = 'chart-empty';
    msg.textContent = 'Charts could not be loaded. Refresh the page to try again.';
    canvas.parentElement.replaceChild(msg, canvas);
  });
}

document.addEventListener('DOMContentLoaded', () => {
  const categoryData = readPageData('categoryData');
  const co2Data = readPageData('co2Data');
  const wantsChart = categoryData !== null || co2Data !== null;

  if (wantsChart && typeof Chart === 'undefined') {
    reportMissingChartLibrary();
  } else {
    if (categoryData) initCategoryChart(categoryData);
    if (co2Data) initCO2Chart(co2Data);
  }

  // The progress bar is pure CSS and does not need Chart.js.
  const goalBar = document.getElementById('goalProgressBar');
  if (goalBar && goalBar.dataset.percent !== undefined) {
    initGoalProgressBar(parseInt(goalBar.dataset.percent, 10) || 0);
  }
});
