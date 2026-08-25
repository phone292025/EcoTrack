<?php
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('participant');

$uid = currentUserId();
$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_goal') {
    validateCsrf($_POST['csrf'] ?? '');
    $target = (int)($_POST['target'] ?? 0);
    $period = $_POST['period'] ?? 'weekly';

    if ($target < 10) {
        setFlash('error', 'Goal target must be at least 10 points.');
    } elseif (!in_array($period, ['weekly', 'monthly'], true)) {
        setFlash('error', 'Please choose a valid goal period.');
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
            setFlash('success', 'Goal saved successfully.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // Redirect so refreshing the page cannot create a second goal.
    redirectToSelf();
}

$flash = takeFlash();

$user           = getUserById($uid);
$impact         = getEcoImpactSummary($uid);
$goal           = getUserGoalProgress($uid);
$recent         = getUserActivityLog($uid, 1, 20);
$challengeStats = getUserChallengeStats($uid);
$announcements  = getRecentAnnouncements(3);
$tips           = getRecentEcoTips(3);
$checkedInToday = hasCheckedInToday($uid);

$pageTitle   = 'Dashboard';
$needsCharts = !empty($goal);
require_once __DIR__ . '/../layout/header.php';
?>

<div class="container page-shell participant-dashboard-shell">
  <section class="card participant-dashboard-hero">
    <div class="participant-dashboard-hero__content">
      <p class="participant-dashboard-hero__eyebrow">Participant dashboard</p>
      <h1 class="participant-dashboard-hero__title">Welcome back, <?= sanitise($user['username'] ?? '') ?></h1>
    </div>
    <div class="participant-dashboard-hero__badges">
      <span class="badge badge-green"><?= (int)($user['streak'] ?? 0) ?> day streak</span>
      <span class="badge badge-blue"><?= (int)($user['points'] ?? 0) ?> pts live</span>
    </div>
  </section>

  <?php foreach ($flash['error'] as $message): ?>
    <div class="flash-message flash-error" role="alert"><?= sanitise($message) ?></div>
  <?php endforeach; ?>
  <?php foreach ($flash['success'] as $message): ?>
    <div class="flash-message flash-success" role="status"><?= sanitise($message) ?></div>
  <?php endforeach; ?>

  <div class="dashboard-grid participant-dashboard-kpis">
    <div class="stat-widget">
      <span class="stat-widget__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 2 9.2 8.6 2 9.2l5.5 4.7L5.8 21 12 17.3 18.2 21l-1.7-7.1L22 9.2l-7.2-.6L12 2Z"/></svg>
      </span>
      <span class="stat-widget__value" id="dashPoints"><?= (int)($user['points'] ?? 0) ?></span>
      <span class="stat-widget__label">Green points</span>
    </div>
    <div class="stat-widget stat-widget--accent">
      <span class="stat-widget__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M4 19h16v2H4v-2Zm1-4 4.5-5.5 3 3.5L16 8l4 7H5Z"/></svg>
      </span>
      <span class="stat-widget__value"><?= sanitise((string)$impact['co2_kg']) ?> kg</span>
      <span class="stat-widget__label">Estimated CO2 saved</span>
    </div>
    <div class="stat-widget stat-widget--info">
      <span class="stat-widget__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M18 3h3v4a5 5 0 0 1-4.1 4.9A5 5 0 0 1 13 15.9V19h3v2H8v-2h3v-3.1a5 5 0 0 1-3.9-4A5 5 0 0 1 3 7V3h3V1h12v2Zm0 2v4.9A3 3 0 0 0 19 7V5h-1ZM5 5v2a3 3 0 0 0 1 2.2V5H5Z"/></svg>
      </span>
      <span class="stat-widget__value"><?= (int)($challengeStats['joined'] ?? 0) ?></span>
      <span class="stat-widget__label">Challenges joined</span>
    </div>
    <div class="stat-widget">
      <span class="stat-widget__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z"/></svg>
      </span>
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
          <button type="submit" class="btn btn-primary participant-dashboard-checkin__button"
                  id="checkinBtn" <?= $checkedInToday ? 'disabled' : '' ?>>
            <?= $checkedInToday ? 'Checked in today' : 'Check in today' ?>
          </button>
        </form>
        <p id="checkinMsg" class="participant-dashboard-checkin__message" role="status">
          <?= $checkedInToday ? 'Come back tomorrow to keep your streak alive.' : '' ?>
        </p>
      </div>

      <div class="card">
        <div class="card-header-row">
          <div class="card-header-row__content">
            <h2 class="card-title participant-dashboard-card-title">Goal setting</h2>
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
            <div class="progress-bar" role="progressbar"
                 aria-valuenow="<?= (int)$goal['percent'] ?>" aria-valuemin="0" aria-valuemax="100"
                 aria-label="Goal progress">
              <div id="goalProgressBar" class="progress-fill" style="width:<?= (int)$goal['percent'] ?>%;"></div>
            </div>
            <p id="goalProgressLabel" class="participant-dashboard-goal-label">
              <?= (int)$goal['points_in_period'] ?> / <?= (int)$goal['target'] ?> points &middot; <?= (int)$goal['percent'] ?>% complete
              <?php if ((int)$goal['days_left'] > 0): ?>
                &middot; <?= (int)$goal['days_left'] ?> day<?= (int)$goal['days_left'] === 1 ? '' : 's' ?> left
              <?php endif; ?>
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
                    <span class="activity-list__status status-<?= sanitise($row['status']) ?>"><?= sanitise($row['status']) ?></span>
                    <?php if ($row['status'] === 'rejected' && !empty($row['review_note'])): ?>
                      <span class="activity-list__note">Moderator note: <?= sanitise($row['review_note']) ?></span>
                    <?php endif; ?>
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
  if (!form || !msg || !btn) return;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (btn.disabled) return;

    msg.textContent = '';
    msg.className = 'participant-dashboard-checkin__message';
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
        msg.classList.add('is-success');
        msg.textContent = data.message || 'Checked in!';
        btn.textContent = 'Checked in today';
        if (ptsEl && data.new_points != null) ptsEl.textContent = data.new_points;
        const badge = document.getElementById('navPointsBadge');
        if (badge && data.new_points != null) badge.textContent = data.new_points + ' pts';
      } else {
        msg.classList.add('is-error');
        msg.textContent = data.message || 'Check-in failed.';
        // Already checked in is a permanent state for today; anything else
        // is worth letting them retry.
        btn.disabled = res.status === 409;
      }
    } catch (err) {
      msg.classList.add('is-error');
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

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
