<?php
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('participant');

$rows = getLeaderboard(50);
$myId = currentUserId();
$pageTitle = 'Leaderboard';
require_once __DIR__ . '/../layout/header.php';
?>

<div class="container page-shell" style="max-width:720px;">
  <div class="section-header">
    <div>
      <h1 class="section-header__title">Leaderboard</h1>
    </div>
    <span class="badge badge-blue"><?= count($rows) ?> ranked</span>
  </div>

  <div class="card">
    <?php if (empty($rows)): ?>
      <p class="card-copy">No participants yet.</p>
    <?php else: ?>
      <ol class="leaderboard-list">
        <?php foreach ($rows as $r): ?>
          <li class="leaderboard-list__item">
            <span class="leaderboard-list__body">
              <strong>#<?= (int)$r['rank'] ?></strong>
              <?= sanitise($r['username']) ?>
              <?php if ((int)$r['user_id'] === $myId): ?>
                <span class="meta-copy" style="display:inline;color:var(--clr-primary);">(you)</span>
              <?php endif; ?>
            </span>
            <span class="leaderboard-list__points"><?= (int)$r['points'] ?> pts</span>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
