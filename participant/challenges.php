<?php
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('participant');

$pdo = getPDO();
$uid = currentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');
    $cid = (int)($_POST['join_challenge_id'] ?? 0);

    if ($cid <= 0) {
        setFlash('error', 'Invalid challenge selected.');
        redirectTo('/participant/challenges.php');
    }

    // Refuse to join something that has already ended, even if the button was
    // rendered before it expired or the id was posted by hand.
    $live = $pdo->prepare(
        'SELECT COUNT(*) FROM challenges c WHERE c.challenge_id = ? AND ' . liveChallengeCondition('c')
    );
    $live->execute([$cid]);

    if ((int)$live->fetchColumn() === 0) {
        setFlash('error', 'That challenge is no longer open to join.');
        redirectTo('/participant/challenges.php');
    }

    try {
        $pdo->prepare(
            'INSERT INTO challenge_participants (challenge_id, user_id) VALUES (?, ?)'
        )->execute([$cid, $uid]);

        setFlash('success', 'Challenge joined. Submit a matching activity for moderator review to complete it.');
        redirectTo('/participant/log_activity.php?challenge_id=' . $cid);
    } catch (PDOException $e) {
        if (isDuplicateKeyError($e)) {
            setFlash('error', 'You have already joined this challenge.');
            redirectTo('/participant/challenges.php');
        }
        throw $e;
    }
}

$flash = takeFlash();

// Only challenges that are open right now — an "active" row whose end date
// has passed is over, and must not be offered.
$stmt = $pdo->query(
    'SELECT c.*, cat.name AS cat_name
     FROM challenges c
     LEFT JOIN categories cat ON cat.cat_id = c.cat_id
     WHERE ' . liveChallengeCondition('c') . '
     ORDER BY COALESCE(c.start_date, DATE(c.created_at)) ASC, c.challenge_id ASC'
);
$list = $stmt ? $stmt->fetchAll() : [];

$joined = [];
if ($list) {
    $j = $pdo->prepare(
        'SELECT cp.challenge_id, cp.completed, cp.completed_at, cp.joined_at
         FROM challenge_participants cp
         WHERE cp.user_id = ?'
    );
    $j->execute([$uid]);
    foreach ($j->fetchAll() as $row) {
        $joined[(int)$row['challenge_id']] = $row;
    }
}

$challengeStats = getUserChallengeStats($uid);
$progressByChallenge = getUserChallengeProgress($uid);

$pageTitle = 'Challenges';
require_once __DIR__ . '/../layout/header.php';
?>

<div class="container page-shell" style="max-width:960px;">
  <div class="section-header">
    <div>
      <h1 class="section-header__title">Challenges</h1>
    </div>
    <span class="badge badge-blue"><?= (int)($challengeStats['completed'] ?? 0) ?> completed</span>
  </div>

  <?php foreach ($flash['error'] as $message): ?>
    <div class="flash-message flash-error" role="alert"><?= sanitise($message) ?></div>
  <?php endforeach; ?>
  <?php foreach ($flash['success'] as $message): ?>
    <div class="flash-message flash-success" role="status"><?= sanitise($message) ?></div>
  <?php endforeach; ?>

  <div class="challenge-board">
    <?php if (empty($list)): ?>
      <div class="card empty-state">
        <h2 class="card-title empty-state__title">No active challenges right now</h2>
        <p class="empty-state__text">Check back soon for new sustainability challenges.</p>
      </div>
    <?php else: ?>
      <?php foreach ($list as $c): ?>
        <?php
        $challengeId = (int)$c['challenge_id'];
        $joinData = $joined[$challengeId] ?? null;
        $isJoined = $joinData !== null;
        $isCompleted = $isJoined && !empty($joinData['completed']);
        $needsCategory = !empty($c['cat_name']) ? $c['cat_name'] : 'any approved activity';
        $logChallengeUrl = 'log_activity.php?challenge_id=' . $challengeId;

        $target = max(1, (int)($c['target_count'] ?? 1));
        $progress = $progressByChallenge[$challengeId] ?? null;
        $done = $progress['done'] ?? 0;
        $percent = $target > 0 ? min(100, (int)round(($done / $target) * 100)) : 0;
        ?>
        <article class="reward-admin-card reward-admin-card--default">
          <div class="reward-admin-card__accent" aria-hidden="true"></div>
          <div class="reward-admin-card__body">
            <div class="reward-admin-card__top">
              <div>
                <h2 class="reward-admin-card__name" style="font-size:1.4rem;"><?= sanitise($c['title']) ?></h2>
                <div class="reward-admin-card__meta">
                  <span class="reward-admin-card__chip reward-admin-card__chip--default"><?= sanitise($c['difficulty'] ?? 'easy') ?></span>
                  <span><?= (int)($c['points'] ?? 0) ?> pts</span>
                  <span><?= $target ?> log<?= $target === 1 ? '' : 's' ?> needed</span>
                  <?php if (!empty($c['cat_name'])): ?><span><?= sanitise($c['cat_name']) ?></span><?php endif; ?>
                </div>
              </div>
              <span class="reward-admin-card__status reward-admin-card__status--<?= $isCompleted ? 'active' : 'draft' ?>">
                <?= $isCompleted ? 'Completed' : ($isJoined ? 'Joined' : 'Open') ?>
              </span>
            </div>

            <p class="reward-admin-card__description"><?= sanitise($c['description'] ?? '') ?></p>

            <div class="reward-admin-card__info">
              <span class="reward-admin-card__stock">
                <?php if ($isCompleted): ?>
                  Completed on <?= sanitise((string)$joinData['completed_at']) ?>
                <?php elseif (!empty($c['cat_name'])): ?>
                  Needs <?= $target ?> approved <?= sanitise($c['cat_name']) ?>
                  <?= $target === 1 ? 'activity' : 'activities' ?>
                <?php else: ?>
                  Needs <?= $target ?> approved
                  <?= $target === 1 ? 'activity' : 'activities' ?>
                <?php endif; ?>
              </span>
              <span class="reward-admin-card__visibility">
                <?= !empty($c['end_date']) ? 'Ends ' . sanitise((string)$c['end_date']) : 'No fixed end date' ?>
              </span>
            </div>

            <?php if ($isJoined && !$isCompleted): ?>
              <div class="challenge-progress">
                <div class="progress-bar" role="progressbar"
                     aria-valuenow="<?= $done ?>" aria-valuemin="0" aria-valuemax="<?= $target ?>"
                     aria-label="Challenge progress">
                  <div class="progress-fill progress-fill--green" style="width:<?= $percent ?>%;"></div>
                </div>
                <p class="challenge-progress__label"><?= $done ?> of <?= $target ?> logged</p>
              </div>
            <?php endif; ?>

            <div class="reward-admin-card__divider" aria-hidden="true"></div>

            <?php if ($isCompleted): ?>
              <button type="button" class="btn btn-primary btn-block" disabled>Completed</button>
            <?php elseif ($isJoined): ?>
              <a href="<?= sanitise($logChallengeUrl) ?>" class="btn btn-outline btn-block">Log matching activity</a>
            <?php else: ?>
              <form method="POST">
                <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
                <input type="hidden" name="join_challenge_id" value="<?= (int)$c['challenge_id'] ?>">
                <button type="submit" class="btn btn-primary btn-block">Join challenge</button>
              </form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
