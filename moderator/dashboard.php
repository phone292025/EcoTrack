<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('moderator', 'admin');

$pdo = getPDO();
$pending = (int)$pdo->query('SELECT COUNT(*) FROM activity_logs WHERE status = "pending"')->fetchColumn();
$flagged = (int)$pdo->query('SELECT COUNT(*) FROM activity_logs WHERE status = "flagged"')->fetchColumn();
$activeChallenges = (int)$pdo->query('SELECT COUNT(*) FROM challenges WHERE status = "active"')->fetchColumn();
$totalChallenges = (int)$pdo->query('SELECT COUNT(*) FROM challenges')->fetchColumn();
$totalTips = (int)$pdo->query('SELECT COUNT(*) FROM eco_tips')->fetchColumn();
$tipsThisWeek = (int)$pdo->query('SELECT COUNT(*) FROM eco_tips WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn();
$endingSoon = (int)$pdo->query(
    'SELECT COUNT(*)
     FROM challenges
     WHERE status = "active"
       AND end_date IS NOT NULL
       AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)'
)->fetchColumn();

$latestChallenge = $pdo->query(
    'SELECT title, status, end_date, created_at
     FROM challenges
     ORDER BY challenge_id DESC
     LIMIT 1'
)->fetch() ?: null;

$latestTip = $pdo->query(
    'SELECT title, created_at
     FROM eco_tips
     ORDER BY created_at DESC
     LIMIT 1'
)->fetch() ?: null;

$queueSummary = $pending > 0
    ? $pending . ' submission' . ($pending === 1 ? '' : 's') . ' waiting for review.'
    : 'Your review queue is clear right now.';
$challengeSummary = $activeChallenges > 0
    ? $activeChallenges . ' live challenge' . ($activeChallenges === 1 ? '' : 's') . ' currently visible to users.'
    : 'No live challenges yet. Publish one to spark activity.';
$tipsSummary = $totalTips > 0
    ? $totalTips . ' eco tip' . ($totalTips === 1 ? '' : 's') . ' available in the library.'
    : 'No eco tips published yet.';

$latestChallengeNote = 'No challenge published yet.';
if ($latestChallenge) {
    if (!empty($latestChallenge['end_date'])) {
        $latestChallengeNote = 'Ends ' . date('M j, Y', strtotime((string)$latestChallenge['end_date'])) . '.';
    } else {
        $latestChallengeNote = 'Published ' . date('M j, Y', strtotime((string)$latestChallenge['created_at'])) . '.';
    }
}

$latestTipNote = $latestTip
    ? 'Last published ' . date('M j, Y', strtotime((string)$latestTip['created_at'])) . '.'
    : 'Share a short sustainability tip to start the library.';

$pageTitle = 'Moderator';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-shell moderator-dashboard-shell">
  <section class="card moderator-dashboard-hero">
    <div class="moderator-dashboard-hero__content">
      <p class="moderator-dashboard-hero__eyebrow">Moderator workspace</p>
      <h1 class="moderator-dashboard-hero__title">Moderator dashboard</h1>
      <p class="moderator-dashboard-hero__text">Review submissions, launch challenges, and publish eco guidance from one polished control room built for fast moderation.</p>
      <div class="moderator-dashboard-hero__actions">
        <a class="btn btn-primary" href="<?= BASE_URL ?>/moderator/review_submissions.php">Open review queue</a>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/moderator/create_challenge.php">Create challenge</a>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/moderator/eco_tips.php">Manage eco tips</a>
      </div>
    </div>

    <div class="moderator-dashboard-hero__aside">
      <div class="badge-group moderator-dashboard-hero__badges">
        <span class="badge badge-amber"><?= $pending ?> pending</span>
        <span class="badge badge-blue"><?= $activeChallenges ?> live challenge<?= $activeChallenges === 1 ? '' : 's' ?></span>
        <span class="badge badge-green"><?= $totalTips ?> eco tip<?= $totalTips === 1 ? '' : 's' ?></span>
        <?php if ($flagged > 0): ?>
          <span class="badge badge-red"><?= $flagged ?> flagged</span>
        <?php endif; ?>
      </div>
      <div class="moderator-dashboard-hero__aside-card">
        <span class="moderator-dashboard-hero__aside-label">Current pulse</span>
        <p class="moderator-dashboard-hero__aside-copy">
          <?= sanitise($pending > 0
              ? 'Pending activity needs attention before the queue starts to grow.'
              : 'The queue is under control, so this is a good time to publish new content.') ?>
        </p>
      </div>
    </div>
  </section>

  <div class="dashboard-grid moderator-dashboard-kpis">
    <div class="stat-widget moderator-dashboard-kpi">
      <span class="moderator-dashboard-kpi__label">Review queue</span>
      <span class="stat-widget__value"><?= $pending ?></span>
      <span class="stat-widget__label">Pending submissions</span>
      <p class="moderator-dashboard-kpi__copy"><?= sanitise($queueSummary) ?></p>
    </div>
    <div class="stat-widget moderator-dashboard-kpi">
      <span class="moderator-dashboard-kpi__label">Challenge coverage</span>
      <span class="stat-widget__value"><?= $activeChallenges ?></span>
      <span class="stat-widget__label">Live challenges</span>
      <p class="moderator-dashboard-kpi__copy"><?= sanitise($challengeSummary) ?></p>
    </div>
    <div class="stat-widget moderator-dashboard-kpi">
      <span class="moderator-dashboard-kpi__label">Tip library</span>
      <span class="stat-widget__value"><?= $totalTips ?></span>
      <span class="stat-widget__label">Published eco tips</span>
      <p class="moderator-dashboard-kpi__copy"><?= sanitise($tipsSummary) ?></p>
    </div>
    <div class="stat-widget moderator-dashboard-kpi">
      <span class="moderator-dashboard-kpi__label">Next 7 days</span>
      <span class="stat-widget__value"><?= $endingSoon ?></span>
      <span class="stat-widget__label">Challenges ending soon</span>
      <p class="moderator-dashboard-kpi__copy"><?= $tipsThisWeek ?> new eco tip<?= $tipsThisWeek === 1 ? '' : 's' ?> this week.</p>
    </div>
  </div>

  <section class="card moderator-dashboard-panel moderator-dashboard-panel--spotlight moderator-dashboard-panel--wide">
    <div class="card-header-row moderator-dashboard-panel__header">
      <div class="card-header-row__content">
        <h2 class="card-title">Publishing pulse</h2>
        <p class="card-header-row__text">Keep an eye on what is live now, what is ending soon, and whether the eco tips library needs fresh content.</p>
      </div>
      <div class="badge-group">
        <span class="badge badge-blue"><?= $activeChallenges ?> live</span>
        <span class="badge badge-green"><?= $totalTips ?> tips</span>
        <?php if ($endingSoon > 0): ?>
          <span class="badge badge-amber"><?= $endingSoon ?> ending soon</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="moderator-spotlight moderator-spotlight--dashboard">
      <div class="moderator-spotlight__card moderator-spotlight__card--feature">
        <span class="moderator-spotlight__eyebrow">Latest challenge</span>
        <h3><?= sanitise($latestChallenge['title'] ?? 'No challenge yet') ?></h3>
        <p><?= sanitise($latestChallengeNote) ?></p>
        <div class="moderator-spotlight__meta">
          <span><?= $activeChallenges ?> live challenge<?= $activeChallenges === 1 ? '' : 's' ?></span>
          <span><?= $totalChallenges ?> total managed</span>
        </div>
        <a class="btn btn-primary" href="<?= BASE_URL ?>/moderator/create_challenge.php">Open challenge studio</a>
      </div>

      <div class="moderator-spotlight__stack">
        <div class="moderator-spotlight__card">
          <span class="moderator-spotlight__eyebrow">Eco tips library</span>
          <h3><?= sanitise($latestTip['title'] ?? 'Publish your first eco tip') ?></h3>
          <p><?= sanitise($latestTipNote) ?></p>
          <div class="moderator-spotlight__meta">
            <span><?= $tipsThisWeek ?> this week</span>
            <span><?= $totalTips ?> total tips</span>
          </div>
        </div>

        <div class="moderator-spotlight__card">
          <span class="moderator-spotlight__eyebrow">Moderator focus</span>
          <h3><?= sanitise($pending > 0 ? 'Review queue needs attention' : 'Publishing time is open') ?></h3>
          <p><?= sanitise($pending > 0 ? 'Clear the waiting submissions first, then return to challenges and eco tips.' : 'The queue is clear, so this is a strong moment to refresh a challenge or add a new tip.') ?></p>
          <div class="moderator-spotlight__meta">
            <span><?= $pending ?> pending</span>
            <span><?= $flagged ?> flagged</span>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
