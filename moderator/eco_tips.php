<?php
require_once __DIR__ . '/../includes/bootstrap.php';

requireRole('moderator', 'admin');

$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');
    $action = $_POST['tip_action'] ?? 'create';

    if ($action === 'delete') {
        $tipId = (int)($_POST['tip_id'] ?? 0);
        if ($tipId <= 0) {
            setFlash('error', 'Invalid eco tip selected.');
        } else {
            $stmt = $pdo->prepare('DELETE FROM eco_tips WHERE tip_id = ?');
            $stmt->execute([$tipId]);
            setFlash('success', $stmt->rowCount() > 0 ? 'Tip deleted.' : 'Eco tip not found.');
        }
    } else {
        $title = trim((string)($_POST['tip_title'] ?? ''));
        $body = trim((string)($_POST['tip_body'] ?? ''));
        if (mb_strlen($title) < 2 || isTooLong($title, TITLE_MAX)) {
            setFlash('error', 'Give the tip a title of 2-' . TITLE_MAX . ' characters.');
            setFormOld(['tip_title' => $title, 'tip_body' => $body]);
        } elseif (isTooLong($body, BODY_MAX)) {
            setFlash('error', 'Keep the tip to ' . BODY_MAX . ' characters or fewer.');
            setFormOld(['tip_title' => $title, 'tip_body' => $body]);
        } else {
            $pdo->prepare(
                'INSERT INTO eco_tips (title, body, created_by) VALUES (?, ?, ?)'
            )->execute([$title, $body, currentUserId()]);
            setFlash('success', 'Tip published.');
        }
    }

    // Redirect so refreshing cannot repeat the submission.
    redirectToSelf($_SERVER['QUERY_STRING'] ?? '');
}

$flash = takeFlash();
$old = takeFormOld();

$list = $pdo->query(
    'SELECT t.*, u.username FROM eco_tips t LEFT JOIN users u ON u.user_id = t.created_by ORDER BY t.created_at DESC'
)->fetchAll() ?: [];

$pageTitle = 'Eco tips';
require_once __DIR__ . '/../layout/header.php';
?>

<div class="container page-shell container--sm">
  <div class="section-header">
    <div>
      <h1 class="section-header__title">Eco tips</h1>
    </div>
    <span class="badge badge-blue"><?= count($list) ?> tip<?= count($list) === 1 ? '' : 's' ?></span>
  </div>

  <?php renderFlash($flash); ?>

  <div class="card mb-4">
    <h2 class="card-title">Add tip</h2>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
      <input type="hidden" name="tip_action" value="create">
      <div class="form-group">
        <label for="tip_title">Title</label>
        <input type="text" id="tip_title" name="tip_title" maxlength="<?= TITLE_MAX ?>" value="<?= sanitise($old['tip_title'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label for="tip_body">Body</label>
        <textarea id="tip_body" name="tip_body" rows="3" maxlength="<?= BODY_MAX ?>"><?= sanitise($old['tip_body'] ?? '') ?></textarea>
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
            <h3 class="entry-list__title"><?= sanitise($t['title']) ?></h3>
            <p class="meta-copy"><?= sanitise($t['username'] ?? 'System') ?> &middot; <?= sanitise($t['created_at']) ?></p>
            <?php if (!empty($t['body'])): ?>
              <p class="entry-list__body"><?= nl2br(sanitise($t['body'])) ?></p>
            <?php endif; ?>
            <form method="POST" class="mt-3">
              <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
              <input type="hidden" name="tip_action" value="delete">
              <input type="hidden" name="tip_id" value="<?= (int)$t['tip_id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this eco tip?">Delete</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
