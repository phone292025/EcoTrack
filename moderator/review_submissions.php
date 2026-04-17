<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('moderator', 'admin');

$pdo = getPDO();
$reviewerId = currentUserId();
$isAdmin = currentRole() === 'admin';
$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');
    $logId = (int)($_POST['log_id'] ?? 0);
    $action = $_POST['submission_action'] ?? '';

    if ($logId <= 0) {
        $err = 'Invalid submission selected.';
    } elseif (!in_array($action, ['approve', 'reject', 'flag'], true)) {
        $err = 'Invalid moderation action.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM activity_logs WHERE log_id = ? FOR UPDATE');
            $stmt->execute([$logId]);
            $log = $stmt->fetch();

            if (!$log) {
                $err = 'Submission not found.';
                $pdo->rollBack();
            } elseif ($action === 'flag' && $log['status'] !== 'pending') {
                $err = 'Only pending submissions can be flagged.';
                $pdo->rollBack();
            } elseif (in_array($action, ['approve', 'reject'], true) && !in_array($log['status'], ['pending', 'flagged'], true)) {
                $err = 'This submission has already been resolved.';
                $pdo->rollBack();
            } elseif ($log['status'] === 'flagged' && !$isAdmin && $action !== 'flag') {
                $err = 'Only admins can resolve flagged submissions.';
                $pdo->rollBack();
            } else {
                if ($action === 'approve') {
                    $pdo->prepare(
                        'UPDATE activity_logs
                         SET status = "approved", reviewed_by = ?, reviewed_at = NOW()
                         WHERE log_id = ?'
                    )->execute([$reviewerId, $logId]);
                    awardPoints((int)$log['user_id'], (int)$log['points'], 'Activity approved', $logId);
                    updateStreak((int)$log['user_id']);
                    refreshUserChallengeProgress((int)$log['user_id']);
                    $ok = 'Submission approved.';
                } elseif ($action === 'reject') {
                    $pdo->prepare(
                        'UPDATE activity_logs
                         SET status = "rejected", reviewed_by = ?, reviewed_at = NOW()
                         WHERE log_id = ?'
                    )->execute([$reviewerId, $logId]);
                    $ok = 'Submission rejected.';
                } else {
                    $pdo->prepare(
                        'UPDATE activity_logs
                         SET status = "flagged", flagged_by = ?, reviewed_by = NULL, reviewed_at = NULL
                         WHERE log_id = ?'
                    )->execute([$reviewerId, $logId]);
                    $ok = 'Submission flagged for admin review.';
                }

                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

$pending = $pdo->query(
    'SELECT al.*, u.username, c.name AS cat_name
     FROM activity_logs al
     JOIN users u ON u.user_id = al.user_id
     JOIN categories c ON c.cat_id = al.cat_id
     WHERE al.status = "pending"
     ORDER BY al.created_at ASC'
)->fetchAll() ?: [];

$flagged = [];
if ($isAdmin) {
    $flagged = $pdo->query(
        'SELECT al.*, u.username, c.name AS cat_name, flagger.username AS flagged_by_name
         FROM activity_logs al
         JOIN users u ON u.user_id = al.user_id
         JOIN categories c ON c.cat_id = al.cat_id
         LEFT JOIN users flagger ON flagger.user_id = al.flagged_by
         WHERE al.status = "flagged"
         ORDER BY al.created_at ASC'
    )->fetchAll() ?: [];
}

$pageTitle = 'Review submissions';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-shell review-page" style="max-width:1080px;">
  <div class="section-header">
    <div>
      <h1 class="section-header__title">Submission review</h1>
      <?php if (!$isAdmin): ?>
        <p class="section-header__text">Moderators can approve, reject, or flag pending activity logs. Admins can also resolve the flagged queue.</p>
      <?php endif; ?>
    </div>
    <div class="badge-group">
      <span class="badge badge-amber"><?= count($pending) ?> pending</span>
      <?php if ($isAdmin): ?>
        <span class="badge badge-red"><?= count($flagged) ?> flagged</span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($err): ?><div class="flash-message flash-error" role="alert"><?= sanitise($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="flash-message flash-success" role="status"><?= sanitise($ok) ?></div><?php endif; ?>

  <div class="panel-stack">
    <div class="card review-panel">
      <h2 class="card-title">Pending submissions</h2>
      <?php if (empty($pending)): ?>
        <p class="card-copy">No pending submissions right now.</p>
      <?php else: ?>
        <?php foreach ($pending as $row): ?>
          <div class="submission-card review-entry">
            <div class="card-header-row">
              <div class="card-header-row__content">
                <p style="margin:0 0 var(--space-1);"><strong><?= sanitise($row['username']) ?></strong> &middot; <?= sanitise($row['cat_name']) ?> &middot; <?= (int)$row['points'] ?> pts</p>
                <p class="meta-copy"><?= sanitise($row['created_at']) ?></p>
              </div>
              <span class="badge badge-amber">pending</span>
            </div>
            <p style="margin:var(--space-3) 0 0;"><?= nl2br(sanitise($row['description'])) ?></p>
            <?php if (!empty($row['evidence'])): ?>
              <div class="submission-evidence">
                <img src="<?= BASE_URL ?>/uploads/evidence/<?= sanitise($row['evidence']) ?>" alt="Evidence image from <?= sanitise($row['username']) ?>">
                <a href="<?= BASE_URL ?>/uploads/evidence/<?= sanitise($row['evidence']) ?>" target="_blank" rel="noopener">Open full image</a>
              </div>
            <?php endif; ?>
            <form method="POST" class="submission-actions" style="margin-top:var(--space-3);">
              <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
              <input type="hidden" name="log_id" value="<?= (int)$row['log_id'] ?>">
              <button type="submit" name="submission_action" value="approve" class="btn btn-primary btn-sm">Approve</button>
              <button type="submit" name="submission_action" value="reject" class="btn btn-outline btn-sm">Reject</button>
              <button type="submit" name="submission_action" value="flag" class="btn btn-danger btn-sm">Flag for admin</button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php if ($isAdmin): ?>
      <div class="card review-panel">
        <h2 class="card-title">Flagged submissions</h2>
        <?php if (empty($flagged)): ?>
          <p class="card-copy">No flagged submissions right now.</p>
        <?php else: ?>
          <?php foreach ($flagged as $row): ?>
            <div class="submission-card review-entry">
              <div class="card-header-row">
                <div class="card-header-row__content">
                  <p style="margin:0 0 var(--space-1);"><strong><?= sanitise($row['username']) ?></strong> &middot; <?= sanitise($row['cat_name']) ?> &middot; <?= (int)$row['points'] ?> pts</p>
                  <p class="meta-copy">Flagged by <?= sanitise($row['flagged_by_name'] ?? 'moderator') ?> &middot; <?= sanitise($row['created_at']) ?></p>
                </div>
                <span class="badge badge-red">flagged</span>
              </div>
              <p style="margin:var(--space-3) 0 0;"><?= nl2br(sanitise($row['description'])) ?></p>
              <?php if (!empty($row['evidence'])): ?>
                <div class="submission-evidence">
                  <img src="<?= BASE_URL ?>/uploads/evidence/<?= sanitise($row['evidence']) ?>" alt="Evidence image from <?= sanitise($row['username']) ?>">
                  <a href="<?= BASE_URL ?>/uploads/evidence/<?= sanitise($row['evidence']) ?>" target="_blank" rel="noopener">Open full image</a>
                </div>
              <?php endif; ?>
              <form method="POST" class="submission-actions" style="margin-top:var(--space-3);">
                <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
                <input type="hidden" name="log_id" value="<?= (int)$row['log_id'] ?>">
                <button type="submit" name="submission_action" value="approve" class="btn btn-primary btn-sm">Approve flagged</button>
                <button type="submit" name="submission_action" value="reject" class="btn btn-outline btn-sm">Reject flagged</button>
              </form>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
