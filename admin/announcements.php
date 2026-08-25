<?php
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');
    $action = $_POST['post_action'] ?? 'create';

    if ($action === 'delete') {
        $annId = (int)($_POST['ann_id'] ?? 0);
        if ($annId <= 0) {
            setFlash('error', 'Invalid announcement selected.');
        } else {
            $stmt = $pdo->prepare('DELETE FROM announcements WHERE ann_id = ?');
            $stmt->execute([$annId]);
            setFlash('success', $stmt->rowCount() > 0 ? 'Announcement deleted.' : 'Announcement not found.');
        }
    } else {
        $title = trim($_POST['ann_title'] ?? '');
        $body = trim($_POST['ann_body'] ?? '');
        if (strlen($title) < 2) {
            setFlash('error', 'Give the announcement a title of at least 2 characters.');
            setFormOld(['ann_title' => $title, 'ann_body' => $body]);
        } else {
            $pdo->prepare(
                'INSERT INTO announcements (title, body, created_by) VALUES (?, ?, ?)'
            )->execute([$title, $body, currentUserId()]);
            setFlash('success', 'Announcement posted.');
        }
    }

    // Redirect so refreshing cannot repeat the submission.
    redirectToSelf($_SERVER['QUERY_STRING'] ?? '');
}

$flash = takeFlash();

$list = $pdo->query(
    'SELECT a.*, u.username FROM announcements a LEFT JOIN users u ON u.user_id = a.created_by ORDER BY a.created_at DESC'
)->fetchAll() ?: [];

$pageTitle = 'Announcements';
require_once __DIR__ . '/../layout/header.php';
?>

<div class="container page-shell" style="max-width:720px;">
  <div class="section-header">
    <div>
      <h1 class="section-header__title">Announcements</h1>
    </div>
    <span class="badge badge-blue"><?= count($list) ?> post<?= count($list) === 1 ? '' : 's' ?></span>
  </div>

  <?php foreach ($flash['error'] as $flashMessage): ?>
    <div class="flash-message flash-error" role="alert"><?= sanitise($flashMessage) ?></div>
  <?php endforeach; ?>
  <?php foreach ($flash['success'] as $flashMessage): ?>
    <div class="flash-message flash-success" role="status"><?= sanitise($flashMessage) ?></div>
  <?php endforeach; ?>

  <div class="card" style="margin-bottom:var(--space-4);">
    <h2 class="card-title">New announcement</h2>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
      <input type="hidden" name="post_action" value="create">
      <div class="form-group">
        <label for="ann_title">Title</label>
        <input type="text" id="ann_title" name="ann_title" maxlength="200" required>
      </div>
      <div class="form-group">
        <label for="ann_body">Body</label>
        <textarea id="ann_body" name="ann_body" rows="3"></textarea>
      </div>
      <button type="submit" class="btn btn-primary">Publish</button>
    </form>
  </div>

  <div class="card">
    <?php if (empty($list)): ?>
      <p class="card-copy">No announcements posted yet.</p>
    <?php else: ?>
      <div class="entry-list">
        <?php foreach ($list as $a): ?>
          <div class="entry-list__item">
            <h3 style="margin:0 0 var(--space-2);"><?= sanitise($a['title']) ?></h3>
            <p class="meta-copy"><?= sanitise($a['username'] ?? '') ?> &middot; <?= sanitise($a['created_at']) ?></p>
            <?php if (!empty($a['body'])): ?>
              <p style="margin:var(--space-2) 0 0;"><?= nl2br(sanitise($a['body'])) ?></p>
            <?php endif; ?>
            <form method="POST" style="margin-top:var(--space-3);">
              <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
              <input type="hidden" name="post_action" value="delete">
              <input type="hidden" name="ann_id" value="<?= (int)$a['ann_id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this announcement?');">Delete</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
