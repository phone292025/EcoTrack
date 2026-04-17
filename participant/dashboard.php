<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('participant');

$uid = currentUserId();
$pdo = getPDO();
$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_goal') {
    validateCsrf($_POST['csrf'] ?? '');
    $target = (int)($_POST['target'] ?? 0);
    $period = $_POST['period'] ?? 'weekly';

    if ($target < 10) {
        $err = 'Goal target must be at least 10 points.';
    } elseif (!in_array($period, ['weekly', 'monthly'], true)) {
        $err = 'Please choose a valid goal period.';
    } else {
        $startDate = new DateTimeImmutable('today');
        $endDate = $period === 'monthly'
            ? $startDate->modify('+29 days')
            : $startDate->modify('+6 days');

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE goals
                 SET end_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                 WHERE user_id = ?
                   AND start_date <= CURDATE()
                   AND end_date >= CURDATE()'
            )->execute([$uid]);

            $pdo->prepare(
                'INSERT INTO goals (user_id, target, period, start_date, end_date)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $uid,
                $target,
                $period,
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d'),
            ]);

            $pdo->commit();
            $ok = 'Goal saved successfully.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

refreshUserChallengeProgress($uid);

$user = getUserById($uid);
$impact = getEcoImpactSummary($uid);
$goal = getUserGoalProgress($uid);
$recent = getUserActivityLog($uid, 1, 20);
$challengeStats = getUserChallengeStats($uid);
$announcements = getRecentAnnouncements(3);
$tips = getRecentEcoTips(3);

$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-shell participant-dashboard-shell">
  <section class="card participant-dashboard-hero">
    <div class="participant-dashboard-hero__content">
      <p class="participant-dashboard-hero__eyebrow">Participant dashboard</p>
      <h1 class="participant-dashboard-hero__title">Welcome back, <?= sanitise($user['username'] ?? '') ?></h1>
      <p class="participant-dashboard-hero__text">Keep your eco momentum going with your daily check-in, goal tracking, and the latest platform updates.</p>
    </div>
    <div class="participant-dashboard-hero__badges">
      <span class="badge badge-green"><?= (int)($user['streak'] ?? 0) ?> day streak</span>
      <span class="badge badge-blue"><?= (int)($user['points'] ?? 0) ?> pts live</span>
    </div>
  </section>

  <?php if ($err): ?><div class="flash-message flash-error" role="alert"><?= sanitise($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="flash-message flash-success" role="status"><?= sanitise($ok) ?></div><?php endif; ?>

  <div class="dashboard-grid participant-dashboard-kpis">
    <div class="stat-widget">
      <span class="stat-widget__icon">Pts</span>
      <span class="stat-widget__value" id="dashPoints"><?= (int)($user['points'] ?? 0) ?></span>
      <span class="stat-widget__label">Green points</span>
    </div>
    <div class="stat-widget stat-widget--accent">
      <span class="stat-widget__icon">CO2</span>
      <span class="stat-widget__value"><?= sanitise((string)$impact['co2_kg']) ?> kg</span>
      <span class="stat-widget__label">Estimated CO2 saved</span>
    </div>
    <div class="stat-widget stat-widget--info">
      <span class="stat-widget__icon">Join</span>
      <span class="stat-widget__value"><?= (int)($challengeStats['joined'] ?? 0) ?></span>
      <span class="stat-widget__label">Challenges joined</span>
    </div>
    <div class="stat-widget">
      <span class="stat-widget__icon">Done</span>
      <span class="stat-widget__value"><?= (int)($challengeStats['completed'] ?? 0) ?></span>
      <span class="stat-widget__label">Challenges completed</span>
    </div>
  </div>

  <div class="split-layout split-layout--dashboard participant-dashboard-layout">
    <div class="panel-stack participant-dashboard-main">
      <div class="card participant-dashboard-checkin">
        <h2 class="card-title">Daily check-in</h2>
        <p class="card-copy participant-dashboard-checkin__copy">Check in once per day for +5 points and to keep your streak moving.</p>
        <form id="checkinForm" class="dashboard-checkin">
          <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
          <button type="submit" class="btn btn-primary participant-dashboard-checkin__button" id="checkinBtn">Check in today</button>
        </form>
        <p id="checkinMsg" class="field-error participant-dashboard-checkin__message" role="status"></p>
      </div>

      <div class="card">
        <div class="card-header-row">
          <div class="card-header-row__content">
            <h2 class="card-title participant-dashboard-card-title">Goal setting</h2>
            <p class="card-header-row__text">Set a weekly or monthly points target and track progress live.</p>
          </div>
          <?php if (!empty($goal)): ?>
            <span class="badge badge-blue"><?= sanitise($goal['period']) ?></span>
          <?php endif; ?>
        </div>

        <?php if (!empty($goal)): ?>
          <div class="panel-stack participant-dashboard-goal-state">
            <p class="participant-dashboard-inline-copy">
              Target <strong><?= (int)$goal['target'] ?></strong> points by <?= sanitise($goal['end_date']) ?>.
            </p>
            <div class="progress-bar">
              <div id="goalProgressBar" class="progress-fill" style="width:<?= (int)$goal['percent'] ?>%;"></div>
            </div>
            <p id="goalProgressLabel" class="participant-dashboard-goal-label">
              <?= (int)$goal['points_in_period'] ?> / <?= (int)$goal['target'] ?> points &middot; <?= (int)$goal['percent'] ?>% complete
            </p>
          </div>
        <?php endif; ?>

        <form method="POST" class="form-card participant-dashboard-goal-form">
          <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
          <input type="hidden" name="action" value="save_goal">
          <div class="form-grid-2">
            <div class="form-group participant-dashboard-form-group">
              <label for="goal_target">Target points</label>
              <input type="number" id="goal_target" name="target" min="10" value="<?= !empty($goal) ? (int)$goal['target'] : 100 ?>">
            </div>
            <div class="form-group participant-dashboard-form-group">
              <label for="goal_period">Period</label>
              <select id="goal_period" name="period">
                <option value="weekly" <?= (!empty($goal) && $goal['period'] === 'weekly') ? 'selected' : '' ?>>weekly</option>
                <option value="monthly" <?= (!empty($goal) && $goal['period'] === 'monthly') ? 'selected' : '' ?>>monthly</option>
              </select>
            </div>
          </div>
          <button type="submit" class="btn btn-outline participant-dashboard-secondary-link">Save goal</button>
        </form>
      </div>

      <div class="card">
        <div class="card-header-row">
          <div class="card-header-row__content">
            <h2 class="card-title participant-dashboard-card-title">Recent activity</h2>
            <p class="card-header-row__text">Your latest approved, pending, or rejected submissions.</p>
          </div>
          <a href="<?= BASE_URL ?>/participant/profile.php" class="btn btn-sm btn-outline participant-dashboard-secondary-link">Open profile</a>
        </div>
        <?php if (empty($recent)): ?>
          <p class="card-copy participant-dashboard-empty-copy">
            No activity yet. <a href="<?= BASE_URL ?>/participant/log_activity.php">Log an activity</a> to get started.
          </p>
        <?php else: ?>
          <div class="participant-dashboard-recent-scroller">
            <ul class="activity-list activity-list--spaced">
              <?php foreach ($recent as $row): ?>
                <li class="activity-list__item">
                  <span class="activity-list__body">
                    <strong><?= sanitise($row['cat_name']) ?></strong>
                    <span class="activity-list__description"><?= sanitise($row['description']) ?></span>
                    <span class="activity-list__status"><?= sanitise($row['status']) ?></span>
                  </span>
                  <span class="activity-list__points"><?= (int)$row['points'] ?> pts</span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel-stack participant-dashboard-side">
      <div class="card">
        <h2 class="card-title">Eco impact</h2>
        <div class="impact-grid">
          <div>
            <div class="impact-item__value"><?= sanitise((string)$impact['co2_kg']) ?></div>
            <div class="impact-item__unit">kg CO2 saved</div>
          </div>
          <div>
            <div class="impact-item__value"><?= sanitise((string)$impact['plastic_bottles']) ?></div>
            <div class="impact-item__unit">bottles avoided</div>
          </div>
          <div>
            <div class="impact-item__value"><?= sanitise((string)$impact['trees_equivalent']) ?></div>
            <div class="impact-item__unit">tree-years</div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header-row">
          <div class="card-header-row__content">
            <h2 class="card-title participant-dashboard-card-title">Platform announcements</h2>
          </div>
          <a href="<?= BASE_URL ?>/participant/points.php" class="btn btn-sm btn-outline participant-dashboard-secondary-link">Points dashboard</a>
        </div>
        <?php if (empty($announcements)): ?>
          <p class="card-copy participant-dashboard-empty-copy">No announcements yet.</p>
        <?php else: ?>
          <div class="info-list">
            <?php foreach ($announcements as $announcement): ?>
              <div class="info-list__item">
                <strong class="info-list__title"><?= sanitise($announcement['title']) ?></strong>
                <p class="info-list__meta"><?= sanitise($announcement['created_at']) ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2 class="card-title">Latest eco tips</h2>
        <?php if (empty($tips)): ?>
          <p class="card-copy">No eco tips posted yet.</p>
        <?php else: ?>
          <div class="info-list">
            <?php foreach ($tips as $tip): ?>
              <div class="info-list__item">
                <strong class="info-list__title"><?= sanitise($tip['title']) ?></strong>
                <p class="info-list__meta"><?= sanitise($tip['body']) ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const form = document.getElementById('checkinForm');
  const msg = document.getElementById('checkinMsg');
  const btn = document.getElementById('checkinBtn');
  const ptsEl = document.getElementById('dashPoints');
  if (!form || !msg) return;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    msg.textContent = '';
    btn.disabled = true;
    try {
      const fd = new FormData(form);
      const res = await fetch('<?= BASE_URL ?>/ajax/checkin.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
        credentials: 'same-origin'
      });
      const data = await res.json();
      if (data.success) {
        msg.style.color = 'var(--clr-primary)';
        msg.textContent = data.message || 'Checked in!';
        if (ptsEl && data.new_points != null) ptsEl.textContent = data.new_points;
        const badge = document.getElementById('navPointsBadge');
        if (badge && data.new_points != null) badge.textContent = data.new_points + ' pts';
        btn.disabled = true;
      } else {
        msg.style.color = 'var(--clr-danger)';
        msg.textContent = data.message || 'Check-in failed.';
        btn.disabled = false;
      }
    } catch (err) {
      msg.style.color = 'var(--clr-danger)';
      msg.textContent = 'Network error. Try again.';
      btn.disabled = false;
    }
  });
})();
</script>

<?php if (!empty($goal)): ?>
  <script>
  const GOAL_PERCENT = <?= (int)$goal['percent'] ?>;
  </script>
  <script src="<?= BASE_URL ?>/assets/js/charts.js"></script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
