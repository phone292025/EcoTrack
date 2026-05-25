<?php
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('moderator', 'admin');

$pdo = getPDO();
$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');
    $action = $_POST['tip_action'] ?? 'create';

    if ($action === 'delete') {
        $tipId = (int)($_POST['tip_id'] ?? 0);
        if (!in_array(currentRole(), ['moderator', 'admin'], true)) {
            $err = 'Only moderators and admins can delete eco tips.';
        } elseif ($tipId <= 0) {
            $err = 'Invalid eco tip selected.';
        } else {
            $stmt = $pdo->prepare('DELETE FROM eco_tips WHERE tip_id = ?');
            $stmt->execute([$tipId]);
            $ok = $stmt->rowCount() > 0 ? 'Tip deleted.' : 'Eco tip not found.';
        }
    } else {
        $title = trim($_POST['tip_title'] ?? '');
        $body = trim($_POST['tip_body'] ?? '');
        if (strlen($title) >= 2) {
            $pdo->prepare(
                'INSERT INTO eco_tips (title, body, created_by) VALUES (?, ?, ?)'
            )->execute([$title, $body, currentUserId()]);
            $ok = 'Tip published.';
        }
    }
}

$list = $pdo->query(
    'SELECT t.*, u.username FROM eco_tips t LEFT JOIN users u ON u.user_id = t.created_by ORDER BY t.created_at DESC'
)->fetchAll() ?: [];

$pageTitle = 'Eco tips';
require_once __DIR__ . '/../layout/header.php';
?>

<div class="container page-shell" style="max-width:720px;">
  <div class="section-header">
    <div>
      <h1 class="section-header__title">Eco tips</h1>
    </div>
    <span class="badge badge-blue"><?= count($list) ?> tip<?= count($list) === 1 ? '' : 's' ?></span>
  </div>

  <?php if ($err): ?><div class="flash-message flash-error" role="alert"><?= sanitise($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="flash-message flash-success"><?= sanitise($ok) ?></div><?php endif; ?>

  <div class="card" style="margin-bottom:var(--space-4);">
    <h2 class="card-title">Add tip</h2>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
      <input type="hidden" name="tip_action" value="create">
      <div class="form-group">
        <label for="tip_title">Title</label>
        <input type="text" id="tip_title" name="tip_title" maxlength="200" required>
      </div>
      <div class="form-group">
        <label for="tip_body">Body</label>
        <textarea id="tip_body" name="tip_body" rows="3"></textarea>
      </div>
      <button type="submit" class="btn btn-primary">Post</button>
    </form>
  </div>

  <div class="card">
    <?php if (empty($list)): ?>
      <p class="card-copy">No eco tips posted yet.</p>
    <?php else: ?>
      <div class="entry-list">
        <?php foreach ($list as $t): ?>
          <div class="entry-list__item">
            <h3 style="margin:0 0 var(--space-2);font-size:var(--text-base);"><?= sanitise($t['title']) ?></h3>
            <p class="meta-copy"><?= sanitise($t['username'] ?? 'System') ?> &middot; <?= sanitise($t['created_at']) ?></p>
            <?php if (!empty($t['body'])): ?>
              <p style="margin:var(--space-2) 0 0;"><?= nl2br(sanitise($t['body'])) ?></p>
            <?php endif; ?>
            <?php if (in_array(currentRole(), ['moderator', 'admin'], true)): ?>
              <form method="POST" style="margin-top:var(--space-3);">
                <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
                <input type="hidden" name="tip_action" value="delete">
                <input type="hidden" name="tip_id" value="<?= (int)$t['tip_id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this eco tip?');">Delete</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
