<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('participant');

$uid = currentUserId();
$user = getUserById($uid);
$history = getPointsHistory($uid, 100);
$categoryData = getCategoryBreakdown($uid);
$earnedTotal = 0;
$spentTotal = 0;

foreach ($history as $row) {
    $delta = (int)$row['delta'];
    if ($delta >= 0) {
        $earnedTotal += $delta;
    } else {
        $spentTotal += abs($delta);
    }
}

$pageTitle = 'Points';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-shell">
  <div class="section-header">
    <div>
      <h1 class="section-header__title">Points dashboard</h1>
      <p class="section-header__text">Track your current balance, review your full points ledger, and see which activity categories are driving your progress.</p>
    </div>
    <span class="badge badge-blue"><?= (int)($user['points'] ?? 0) ?> pts</span>
  </div>

  <div class="dashboard-grid points-summary-grid">
    <div class="stat-widget points-stat-widget">
      <span class="stat-widget__icon">Now</span>
      <span class="stat-widget__value"><?= (int)($user['points'] ?? 0) ?></span>
      <span class="stat-widget__label">Current balance</span>
    </div>
    <div class="stat-widget stat-widget--accent points-stat-widget">
      <span class="stat-widget__icon">Earned</span>
      <span class="stat-widget__value"><?= $earnedTotal ?></span>
      <span class="stat-widget__label">Total earned</span>
    </div>
    <div class="stat-widget stat-widget--info points-stat-widget">
      <span class="stat-widget__icon">Spent</span>
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
            <p class="card-header-row__text">Your latest transactions from activity approvals, daily check-ins, bonuses, and reward redemptions.</p>
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

      <div class="card points-note-card">
        <h2 class="card-title">Quick note</h2>
        <div class="points-note-card__body">
          <p class="card-copy">Positive values mean points earned. Negative values mean points spent in the Green Shop or other deductions.</p>
          <p class="meta-copy">If your balance changes after moderation review, the approved activity or challenge reward will appear here automatically.</p>
        </div>
      </div>
    </div>

    <aside class="points-layout__side">
      <div class="card points-card points-card--chart">
        <div class="card-header-row">
          <div class="card-header-row__content">
            <h2 class="card-title">Category breakdown</h2>
            <p class="card-header-row__text">See which eco activity categories are contributing the most points to your progress.</p>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
