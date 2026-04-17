/**
 * EcoTrack — Chart Helpers
 * File: assets/js/charts.js
 *
 * Usage: Include this script on pages that need charts.
 * Chart data is passed from PHP via inline <script> blocks:
 *
 *   <script>
 *     const CATEGORY_DATA = <?= json_encode(getCategoryBreakdown($userId)) ?>;
 *     const CO2_DATA      = <?= json_encode(getCO2Savings($userId)) ?>;
 *   </script>
 *   <script src="{BASE_URL}/assets/js/charts.js"></script>  (BASE_URL = '' or /ecotrack, etc.)
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
        legend: { position: 'top' },
        tooltip: {
          callbacks: {
            label: (ctx) => ` ${ctx.parsed.y.toFixed(3)} kg CO₂ saved`,
          },
        },
      },
      scales: {
        x: {
          grid : { display: false },
          ticks: { maxTicksLimit: 8, maxRotation: 45 },
        },
        y: {
          beginAtZero: true,
          ticks: {
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
  const bar   = document.getElementById('goalProgressBar');
  const label = document.getElementById('goalProgressLabel');
  if (!bar) return;

  percent = Math.min(100, Math.max(0, percent));
  bar.style.width = percent + '%';
  bar.setAttribute('aria-valuenow', percent);

  // Colour-coded: red < 33%, amber < 66%, green >= 66%
  bar.className = 'progress-fill';
  if (percent >= 66)      bar.classList.add('progress-fill--green');
  else if (percent >= 33) bar.classList.add('progress-fill--amber');
  else                    bar.classList.add('progress-fill--red');

  if (label) label.textContent = percent + '%';
}

/* ═══════════════════════════════════════════════════════════
 *  AUTO-INIT on DOMContentLoaded
 * ═══════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  if (typeof CATEGORY_DATA !== 'undefined') initCategoryChart(CATEGORY_DATA);
  if (typeof CO2_DATA      !== 'undefined') initCO2Chart(CO2_DATA);
  if (typeof GOAL_PERCENT  !== 'undefined') initGoalProgressBar(GOAL_PERCENT);
});
