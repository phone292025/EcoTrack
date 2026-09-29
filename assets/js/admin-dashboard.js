/**
 * EcoTrack — Admin dashboard: the collapsible operations card and the
 * platform charts. File: assets/js/admin-dashboard.js
 *
 * Chart data comes from the adminCo2Data and adminCategoryData JSON blocks
 * rendered by admin/dashboard.php.
 */

'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const ADMIN_CO2_DATA = readPageData('adminCo2Data') || { labels: [], data: [] };
  const ADMIN_CATEGORY_DATA = readPageData('adminCategoryData') || { labels: [], points: [], co2: [], colors: [] };

  const mobileQuery = window.matchMedia('(max-width: 768px)');
  const opsCard = document.querySelector('.admin-ops-card');
  const opsToggle = document.getElementById('adminOpsToggle');

  const syncOpsState = () => {
    if (!opsCard || !opsToggle) return;

    const collapsed = mobileQuery.matches;
    opsCard.classList.toggle('is-collapsed', collapsed);
    opsToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    opsToggle.textContent = collapsed ? 'Show details' : 'Hide details';
  };

  if (opsCard && opsToggle) {
    syncOpsState();

    opsToggle.addEventListener('click', () => {
      if (!mobileQuery.matches) return;

      const collapsed = opsCard.classList.toggle('is-collapsed');
      opsToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      opsToggle.textContent = collapsed ? 'Show details' : 'Hide details';
    });

    mobileQuery.addEventListener('change', syncOpsState);
  }

  if (typeof Chart === 'undefined') return;

  const renderEmptyState = (canvasId, message) => {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !canvas.parentElement) return;
    const empty = document.createElement('p');
    empty.className = 'chart-empty';
    empty.textContent = message;
    canvas.parentElement.replaceChild(empty, canvas);
  };

  const co2Canvas = document.getElementById('adminCo2Chart');
  if (co2Canvas) {
    const hasCo2Data = ADMIN_CO2_DATA.data.some((value) => value > 0);
    if (!hasCo2Data) {
      renderEmptyState('adminCo2Chart', 'The platform carbon trend will appear after the first approved activity logs are recorded.');
    } else {
      new Chart(co2Canvas, {
        type: 'line',
        data: {
          labels: ADMIN_CO2_DATA.labels,
          datasets: [{
            label: 'Cumulative CO2 saved (kg)',
            data: ADMIN_CO2_DATA.data,
            borderColor: '#2d936c',
            backgroundColor: 'rgba(45,147,108,0.12)',
            fill: true,
            tension: 0.32,
            borderWidth: 2.5,
            pointRadius: 3,
            pointHoverRadius: 4,
          }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: (ctx) => ` ${ctx.parsed.y.toFixed(3)} kg saved`,
              },
            },
          },
          scales: {
            x: {
              grid: { display: false },
              ticks: { maxTicksLimit: 8 },
            },
            y: {
              beginAtZero: true,
              ticks: {
                callback: (value) => `${Number(value).toFixed(2)} kg`,
              },
              grid: { color: 'rgba(0,0,0,0.06)' },
            },
          },
        },
      });
    }
  }

  const categoryCanvas = document.getElementById('adminCategoryChart');
  if (categoryCanvas) {
    const totalCategoryPoints = ADMIN_CATEGORY_DATA.points.reduce((sum, value) => sum + value, 0);
    if (totalCategoryPoints === 0) {
      renderEmptyState('adminCategoryChart', 'Category impact will show up once approved activities are added across the platform.');
    } else {
      new Chart(categoryCanvas, {
        type: 'bar',
        data: {
          labels: ADMIN_CATEGORY_DATA.labels,
          datasets: [{
            label: 'Approved points',
            data: ADMIN_CATEGORY_DATA.points,
            backgroundColor: ADMIN_CATEGORY_DATA.colors,
            borderRadius: 10,
            borderSkipped: false,
          }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: false,
          indexAxis: 'y',
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: (ctx) => {
                  const co2 = ADMIN_CATEGORY_DATA.co2[ctx.dataIndex] ?? 0;
                  return ` ${ctx.label}: ${ctx.parsed.x} pts / ${Number(co2).toFixed(3)} kg CO2`;
                },
              },
            },
          },
          scales: {
            x: {
              beginAtZero: true,
              grid: { color: 'rgba(0,0,0,0.06)' },
            },
            y: {
              grid: { display: false },
              ticks: {
                font: { size: 12 },
              },
            },
          },
        },
      });
    }
  }
});
