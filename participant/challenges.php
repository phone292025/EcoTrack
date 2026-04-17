<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('participant');

$pdo = getPDO();
$uid = currentUserId();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');
    $cid = (int)($_POST['join_challenge_id'] ?? 0);
    if ($cid > 0) {
        try {
            $pdo->prepare(
                'INSERT INTO challenge_participants (challenge_id, user_id) VALUES (?, ?)'
            )->execute([$cid, $uid]);
            $_SESSION['flash_success'] = 'Challenge joined. Submit a matching activity for moderator review to complete it.';
            header('Location: log_activity.php?challenge_id=' . $cid);
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $msg = 'Already joined this challenge.';
            } else {
                throw $e;
            }
        }
    }
}

refreshUserChallengeProgress($uid);

$stmt = $pdo->query(
    'SELECT c.*, cat.name AS cat_name
     FROM challenges c
     LEFT JOIN categories cat ON cat.cat_id = c.cat_id
     WHERE c.status = "active"
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

$pageTitle = 'Challenges';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-shell" style="max-width:960px;">
  <div class="section-header">
    <div>
      <h1 class="section-header__title">Challenges</h1>
      <p class="section-header__text">Join active eco challenges, track whether you have completed them, and earn challenge bonus points once your matching activity is approved.</p>
    </div>
    <span class="badge badge-blue"><?= (int)($challengeStats['completed'] ?? 0) ?> completed</span>
  </div>

  <?php if ($msg): ?>
    <div class="flash-message flash-success" role="status"><?= sanitise($msg) ?></div>
  <?php endif; ?>

  <div class="challenge-board">
    <?php if (empty($list)): ?>
      <div class="card empty-state">
        <h2 class="card-title empty-state__title">No active challenges right now</h2>
        <p class="empty-state__text">Check back soon for new sustainability challenges.</p>
      </div>
    <?php else: ?>
      <?php foreach ($list as $c): ?>
        <?php
        $joinData = $joined[(int)$c['challenge_id']] ?? null;
        $isJoined = $joinData !== null;
        $isCompleted = $isJoined && !empty($joinData['completed']);
        $needsCategory = !empty($c['cat_name']) ? $c['cat_name'] : 'any approved activity';
        $logChallengeUrl = 'log_activity.php?challenge_id=' . (int)$c['challenge_id'];
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
                <?= $isCompleted ? 'Completed on ' . sanitise((string)$joinData['completed_at']) : 'Complete one approved ' . sanitise($needsCategory) . ' log' ?>
              </span>
              <span class="reward-admin-card__visibility">
                <?= !empty($c['end_date']) ? 'Ends ' . sanitise((string)$c['end_date']) : 'No fixed end date' ?>
              </span>
            </div>

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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
