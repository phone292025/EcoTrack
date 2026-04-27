<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$pdo = getPDO();

$roleCounts = [
    'participant' => 0,
    'moderator' => 0,
    'admin' => 0,
];

$roleRows = $pdo->query('SELECT role, COUNT(*) AS total FROM users GROUP BY role')->fetchAll() ?: [];
foreach ($roleRows as $row) {
    $role = (string)($row['role'] ?? '');
    if (array_key_exists($role, $roleCounts)) {
        $roleCounts[$role] = (int)$row['total'];
    }
}

$userCount = array_sum($roleCounts);
$participantCount = $roleCounts['participant'];
$moderatorCount = $roleCounts['moderator'];
$adminCount = $roleCounts['admin'];

$statusCounts = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'flagged' => 0,
];

$statusRows = $pdo->query('SELECT status, COUNT(*) AS total FROM activity_logs GROUP BY status')->fetchAll() ?: [];
foreach ($statusRows as $row) {
    $status = (string)($row['status'] ?? '');
    if (array_key_exists($status, $statusCounts)) {
        $statusCounts[$status] = (int)$row['total'];
    }
}

$pendingCount = $statusCounts['pending'];
$approvedCount = $statusCounts['approved'];
$rejectedCount = $statusCounts['rejected'];
$flaggedCount = $statusCounts['flagged'];
$submissionCount = array_sum($statusCounts);
$approvalRate = $submissionCount > 0 ? (int)round(($approvedCount / $submissionCount) * 100) : 0;

$activeUsers = (int)$pdo->query(
    'SELECT COUNT(*)
     FROM (
       SELECT DISTINCT user_id
       FROM activity_logs
       WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
       UNION
       SELECT DISTINCT user_id
       FROM daily_checkins
       WHERE checkin_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     ) AS active_users'
)->fetchColumn();

$totalPoints = (int)$pdo->query(
    'SELECT COALESCE(SUM(CASE WHEN delta > 0 THEN delta ELSE 0 END), 0) FROM points_transactions'
)->fetchColumn();

$totalBadgesAwarded = (int)$pdo->query('SELECT COUNT(*) FROM user_badges')->fetchColumn();
$redemptionCount = (int)$pdo->query('SELECT COUNT(*) FROM redemptions')->fetchColumn();
$recentRedemptions = (int)$pdo->query(
    'SELECT COUNT(*)
     FROM redemptions
     WHERE redeemed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
)->fetchColumn();

$lowStockRewards = (int)$pdo->query(
    'SELECT COUNT(*)
     FROM rewards
     WHERE active = 1
       AND stock BETWEEN 1 AND 10'
)->fetchColumn();

$soldOutRewards = (int)$pdo->query(
    'SELECT COUNT(*)
     FROM rewards
     WHERE active = 1
       AND stock = 0'
)->fetchColumn();

$platformCo2Kg = (float)$pdo->query(
    'SELECT COALESCE(SUM(al.points * c.co2_per_point), 0)
     FROM activity_logs al
     JOIN categories c ON c.cat_id = al.cat_id
     WHERE al.status = "approved"'
)->fetchColumn();

$challengeJoined = (int)$pdo->query('SELECT COUNT(*) FROM challenge_participants')->fetchColumn();
$challengeCompleted = (int)$pdo->query(
    'SELECT COUNT(*)
     FROM challenge_participants
     WHERE completed = 1'
)->fetchColumn();
$completionRate = $challengeJoined > 0 ? (int)round(($challengeCompleted / $challengeJoined) * 100) : 0;

$challengePerformance = $pdo->query(
    'SELECT c.title, c.status,
            COUNT(cp.id) AS joined_count,
            COALESCE(SUM(CASE WHEN cp.completed = 1 THEN 1 ELSE 0 END), 0) AS completed_count
     FROM challenges c
     LEFT JOIN challenge_participants cp ON cp.challenge_id = c.challenge_id
     GROUP BY c.challenge_id, c.title, c.status, c.created_at
     ORDER BY joined_count DESC, c.created_at DESC
     LIMIT 5'
)->fetchAll() ?: [];

$recentAnnouncements = getRecentAnnouncements(2);

$categoryRows = $pdo->query(
    'SELECT c.name,
            COALESCE(SUM(CASE WHEN al.status = "approved" THEN al.points ELSE 0 END), 0) AS total_points,
            COALESCE(SUM(CASE WHEN al.status = "approved" THEN al.points * c.co2_per_point ELSE 0 END), 0) AS total_co2
     FROM categories c
     LEFT JOIN activity_logs al ON al.cat_id = c.cat_id
     GROUP BY c.cat_id, c.name
     ORDER BY c.cat_id ASC'
)->fetchAll() ?: [];

$categoryChart = [
    'labels' => [],
    'points' => [],
    'co2' => [],
    'colors' => ['#2d936c', '#f4a261', '#457b9d', '#7b5ea7'],
];

$topCategory = null;
foreach ($categoryRows as $row) {
    $categoryChart['labels'][] = $row['name'];
    $categoryChart['points'][] = (int)$row['total_points'];
    $categoryChart['co2'][] = round((float)$row['total_co2'], 3);

    if ($topCategory === null || (int)$row['total_points'] > (int)$topCategory['total_points']) {
        $topCategory = $row;
    }
}

$co2Rows = $pdo->query(
    'SELECT DATE(al.created_at) AS activity_date,
            COALESCE(SUM(al.points * c.co2_per_point), 0) AS total_co2
     FROM activity_logs al
     JOIN categories c ON c.cat_id = al.cat_id
     WHERE al.status = "approved"
       AND al.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
     GROUP BY DATE(al.created_at)
     ORDER BY activity_date ASC'
)->fetchAll() ?: [];

$co2ByDay = [];
foreach ($co2Rows as $row) {
    $co2ByDay[$row['activity_date']] = (float)$row['total_co2'];
}

$adminCo2Chart = [
    'labels' => [],
    'data' => [],
];

$co2RunningTotal = 0.0;
$today = new DateTimeImmutable('today');
for ($i = 29; $i >= 0; $i--) {
    $date = $today->sub(new DateInterval('P' . $i . 'D'));
    $dateKey = $date->format('Y-m-d');
    $co2RunningTotal += $co2ByDay[$dateKey] ?? 0.0;
    $adminCo2Chart['labels'][] = $date->format('M j');
    $adminCo2Chart['data'][] = round($co2RunningTotal, 3);
}

$pageTitle = 'Admin';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-shell admin-analytics-shell">
  <div class="section-header admin-analytics-header">
    <div>
      <h1 class="section-header__title">Admin analytics dashboard</h1>
      <p class="section-header__text">A streamlined control center for platform health, sustainability impact, moderation pressure, and reward operations.</p>
    </div>
    <span class="badge badge-blue"><?= $participantCount ?> participant<?= $participantCount === 1 ? '' : 's' ?></span>
  </div>

  <div class="dashboard-grid admin-dashboard-kpis admin-dashboard-kpis--main">
    <article class="stat-widget admin-kpi-card">
      <span class="stat-widget__icon">Users</span>
      <span class="stat-widget__value"><?= $userCount ?></span>
      <span class="stat-widget__label">Accounts across all roles</span>
      <span class="admin-kpi-card__detail"><?= $participantCount ?> participants / <?= $moderatorCount ?> moderators / <?= $adminCount ?> admins</span>
    </article>

    <article class="stat-widget stat-widget--accent admin-kpi-card">
      <span class="stat-widget__icon">CO2</span>
      <span class="stat-widget__value stat-widget__value--wrap"><?= number_format($platformCo2Kg, 2) ?> kg</span>
      <span class="stat-widget__label">Platform carbon savings</span>
      <span class="admin-kpi-card__detail">Calculated from approved activities only</span>
    </article>

    <article class="stat-widget admin-kpi-card">
      <span class="stat-widget__icon">Review</span>
      <span class="stat-widget__value"><?= $pendingCount + $flaggedCount ?></span>
      <span class="stat-widget__label">Items in moderation queue</span>
      <span class="admin-kpi-card__detail"><?= $flaggedCount ?> flagged / <?= $pendingCount ?> pending</span>
    </article>
  </div>

  <section class="card admin-ops-card">
    <div class="admin-card-header">
      <div>
        <h2 class="card-title">Operations pulse</h2>
        <p class="admin-card-copy">A compact summary of the numbers that usually matter most during daily admin checks.</p>
      </div>
      <button
        type="button"
        class="btn btn-outline admin-ops-toggle"
        id="adminOpsToggle"
        aria-expanded="true"
        aria-controls="adminOpsGrid"
      >
        Hide details
      </button>
    </div>
    <div class="admin-ops-grid" id="adminOpsGrid">
      <div class="admin-ops-metric">
        <span class="admin-ops-metric__label">Approval rate</span>
        <strong class="admin-ops-metric__value"><?= $approvalRate ?>%</strong>
      </div>
      <div class="admin-ops-metric">
        <span class="admin-ops-metric__label">Points distributed</span>
        <strong class="admin-ops-metric__value"><?= number_format($totalPoints) ?></strong>
      </div>
      <div class="admin-ops-metric">
        <span class="admin-ops-metric__label">Reward redemptions</span>
        <strong class="admin-ops-metric__value"><?= $redemptionCount ?></strong>
      </div>
      <div class="admin-ops-metric">
        <span class="admin-ops-metric__label">Challenge completion</span>
        <strong class="admin-ops-metric__value"><?= $completionRate ?>%</strong>
      </div>
      <div class="admin-ops-metric">
        <span class="admin-ops-metric__label">Badges awarded</span>
        <strong class="admin-ops-metric__value"><?= number_format($totalBadgesAwarded) ?></strong>
      </div>
      <div class="admin-ops-metric">
        <span class="admin-ops-metric__label">Low stock rewards</span>
        <strong class="admin-ops-metric__value"><?= $lowStockRewards ?></strong>
      </div>
    </div>
  </section>

  <div class="admin-focus-grid">
    <section class="card admin-chart-card admin-chart-card--primary">
      <div class="admin-card-header">
        <div>
          <h2 class="card-title">Platform carbon trend</h2>
          <p class="admin-card-copy">Cumulative CO2 savings across the last 30 days, based on approved eco activities across the whole platform.</p>
        </div>
        <div class="admin-card-metrics">
          <span class="inline-pill-note"><?= number_format($platformCo2Kg, 2) ?> kg total</span>
          <span class="inline-pill-note"><?= $activeUsers ?> active users</span>
        </div>
      </div>
      <div class="admin-chart-area admin-chart-area--wide">
        <canvas id="adminCo2Chart"></canvas>
      </div>
    </section>

    <section class="card admin-side-card">
      <div class="admin-card-header">
        <div>
          <h2 class="card-title">Platform mix</h2>
          <p class="admin-card-copy">A smaller side panel for category output and moderation health instead of another large block of sections.</p>
        </div>
      </div>
      <div class="admin-side-card__chart">
        <canvas id="adminCategoryChart"></canvas>
      </div>
      <div class="admin-side-stats">
        <div class="admin-side-stat">
          <span class="admin-side-stat__label">Top category</span>
          <strong class="admin-side-stat__value"><?= sanitise($topCategory['name'] ?? 'No data yet') ?></strong>
          <span class="admin-side-stat__meta"><?= (int)($topCategory['total_points'] ?? 0) ?> approved pts</span>
        </div>
        <div class="admin-side-stat">
          <span class="admin-side-stat__label">Approved</span>
          <strong class="admin-side-stat__value"><?= $approvedCount ?></strong>
          <span class="admin-side-stat__meta"><?= $rejectedCount ?> rejected</span>
        </div>
        <div class="admin-side-stat">
          <span class="admin-side-stat__label">Pending / flagged</span>
          <strong class="admin-side-stat__value"><?= $pendingCount + $flaggedCount ?></strong>
          <span class="admin-side-stat__meta"><?= $flaggedCount ?> flagged need attention</span>
        </div>
        <div class="admin-side-stat">
          <span class="admin-side-stat__label">Reward health</span>
          <strong class="admin-side-stat__value"><?= $lowStockRewards ?></strong>
          <span class="admin-side-stat__meta"><?= $soldOutRewards ?> sold out</span>
        </div>
      </div>
    </section>
  </div>

</div>

<script>
const ADMIN_CO2_DATA = <?= json_encode($adminCo2Chart, JSON_UNESCAPED_SLASHES) ?>;
const ADMIN_CATEGORY_DATA = <?= json_encode($categoryChart, JSON_UNESCAPED_SLASHES) ?>;

document.addEventListener('DOMContentLoaded', () => {
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
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
