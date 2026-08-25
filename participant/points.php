<?php
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('participant');

$uid = currentUserId();
$user = getUserById($uid);
$history = getPointsHistory($uid, 100);
$categoryData = getCategoryBreakdown($uid);

// Lifetime totals come from the whole ledger, not just the page of history
// shown below — otherwise they stop growing past the display limit.
$totals = getPointsTotals($uid);
$earnedTotal = $totals['earned'];
$spentTotal = $totals['spent'];

$pageTitle = 'Points';
$needsCharts = true;
require_once __DIR__ . '/../layout/header.php';
?>

<div class="container page-shell">
  <div class="section-header">
    <div>
      <h1 class="section-header__title">Points dashboard</h1>
    </div>
    <span class="badge badge-blue"><?= (int)($user['points'] ?? 0) ?> pts</span>
  </div>

  <div class="dashboard-grid points-summary-grid">
    <div class="stat-widget points-stat-widget">
      <span class="stat-widget__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm1 10.6 4 2.3-1 1.7-5-2.9V6h2Z"/></svg></span>
      <span class="stat-widget__value"><?= (int)($user['points'] ?? 0) ?></span>
      <span class="stat-widget__label">Current balance</span>
    </div>
    <div class="stat-widget stat-widget--accent points-stat-widget">
      <span class="stat-widget__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 4l7 7h-4v9h-6v-9H5l7-7Z"/></svg></span>
      <span class="stat-widget__value"><?= $earnedTotal ?></span>
      <span class="stat-widget__label">Total earned</span>
    </div>
    <div class="stat-widget stat-widget--info points-stat-widget">
      <span class="stat-widget__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 20l-7-7h4V4h6v9h4l-7 7Z"/></svg></span>
      <span class="stat-widget__value"><?= $spentTotal ?></span>
      <span class="stat-widget__label">Total spent</span>
    </div>
  </div>

  <div class="points-layout">
    <div class="points-layout__main">
      <div class="card points-card points-card--history">
        <div class="card-header-row">
          <div class="card-header-row__content">
            <h2 class="card-title">Points history</h2>
          </div>
        </div>

        <?php if (empty($history)): ?>
          <div class="points-empty-state">
            <p class="card-copy">You have no point transactions yet.</p>
            <p class="meta-copy">Log an activity or complete a daily check-in to start building your ledger.</p>
          </div>
        <?php else: ?>
          <div class="points-history-scroller">
            <div class="table-wrap points-table-wrap">
              <table class="points-history-table">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Reason</th>
                    <th>Delta</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($history as $row): ?>
                    <?php $delta = (int)$row['delta']; ?>
                    <tr>
                      <td data-label="Date"><?= sanitise((string)$row['created_at']) ?></td>
                      <td data-label="Reason"><?= sanitise($row['reason'] ?: 'Points update') ?></td>
                      <td data-label="Delta" class="points-history-table__delta <?= $delta >= 0 ? 'points-history-table__delta--positive' : 'points-history-table__delta--negative' ?>">
                        <?= $delta >= 0 ? '+' : '' ?><?= $delta ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <aside class="points-layout__side">
      <div class="card points-card points-card--chart">
        <div class="card-header-row">
          <div class="card-header-row__content">
            <h2 class="card-title">Category breakdown</h2>
          </div>
        </div>
        <div class="chart-container points-chart-container">
          <canvas id="categoryChart" aria-label="Activity category breakdown"></canvas>
        </div>
      </div>
    </aside>
  </div>
</div>

<script>
const CATEGORY_DATA = <?= json_encode($categoryData, JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= BASE_URL ?>/assets/js/charts.js"></script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
