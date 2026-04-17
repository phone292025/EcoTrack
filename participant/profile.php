<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('participant');

$uid = currentUserId();
refreshUserChallengeProgress($uid);

$user = getUserById($uid);
$badges = getUserBadges($uid);
$impact = getEcoImpactSummary($uid);
$co2Data = getCO2Savings($uid);
$history = getUserActivityLog($uid, 1, 50);
$challengeStats = getUserChallengeStats($uid);

$pageTitle = 'Profile';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-shell profile-page">
  <div class="section-header">
    <div>
      <h1 class="section-header__title">Your profile</h1>
      <p class="section-header__text">Review your eco performance, carbon savings, badges, and complete activity history in one responsive overview.</p>
    </div>
    <span class="badge badge-blue"><?= (int)($user['points'] ?? 0) ?> pts</span>
  </div>

  <div class="dashboard-grid" style="margin-bottom:var(--space-4);">
    <div class="stat-widget">
      <span class="stat-widget__icon">User</span>
      <span class="stat-widget__value stat-widget__value--wrap"><?= sanitise($user['username'] ?? '') ?></span>
      <span class="stat-widget__label stat-widget__label--wrap"><?= sanitise($user['email'] ?? '') ?></span>
    </div>
    <div class="stat-widget stat-widget--accent">
      <span class="stat-widget__icon">Streak</span>
      <span class="stat-widget__value"><?= (int)($user['streak'] ?? 0) ?></span>
      <span class="stat-widget__label">Current day streak</span>
    </div>
    <div class="stat-widget stat-widget--info">
      <span class="stat-widget__icon">Done</span>
      <span class="stat-widget__value"><?= (int)($challengeStats['completed'] ?? 0) ?></span>
      <span class="stat-widget__label">Challenges completed</span>
    </div>
  </div>

  <div class="split-layout split-layout--profile profile-layout">
    <div class="panel-stack profile-layout__main">
      <div class="card profile-chart-card">
        <h2 class="card-title">Carbon footprint graph</h2>
        <div class="chart-container profile-chart-card__chart">
          <canvas id="co2Chart" aria-label="Carbon footprint savings graph"></canvas>
        </div>
        <div class="profile-chart-card__impact">
          <div class="profile-impact-grid">
          <div class="profile-impact-stat">
            <span class="profile-impact-stat__label">KG CO2 saved</span>
            <span class="profile-impact-stat__value"><?= sanitise((string)$impact['co2_kg']) ?></span>
          </div>
          <div class="profile-impact-stat">
            <span class="profile-impact-stat__label">Bottles avoided</span>
            <span class="profile-impact-stat__value"><?= sanitise((string)$impact['plastic_bottles']) ?></span>
          </div>
          <div class="profile-impact-stat">
            <span class="profile-impact-stat__label">Tree-years</span>
            <span class="profile-impact-stat__value"><?= sanitise((string)$impact['trees_equivalent']) ?></span>
          </div>
        </div>
        </div>
      </div>
    </div>

    <div class="panel-stack profile-layout__side">
      <div class="card profile-history-card">
        <div class="card-header-row profile-history-card__header">
          <div>
            <h2 class="card-title" style="margin:0;">Activity history</h2>
            <p class="card-copy profile-history-card__copy">Your most recent eco submissions and moderation outcomes.</p>
          </div>
          <span class="inline-pill-note"><?= count($history) ?> recent</span>
        </div>
        <?php if (empty($history)): ?>
          <p class="card-copy">No activity history yet.</p>
        <?php else: ?>
          <div class="profile-history-card__scroller">
            <ul class="activity-list activity-list--profile">
              <?php foreach ($history as $row): ?>
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
  </div>

  <div class="card" style="margin-top:var(--space-4);">
    <h2 class="card-title">Badge gallery</h2>
    <div class="badge-gallery badge-gallery--profile">
      <?php foreach ($badges as $badge): ?>
        <div class="badge-item <?= empty($badge['earned']) ? 'badge-item--locked' : '' ?>">
          <div class="badge-item__name"><?= sanitise($badge['name']) ?></div>
          <div class="badge-item__hint"><?= sanitise($badge['description'] ?? '') ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
const CO2_DATA = <?= json_encode($co2Data, JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= BASE_URL ?>/assets/js/charts.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
