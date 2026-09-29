<?php
require_once __DIR__ . '/../includes/bootstrap.php';

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
        $title = trim((string)($_POST['ann_title'] ?? ''));
        $body = trim((string)($_POST['ann_body'] ?? ''));
        if (mb_strlen($title) < 2 || isTooLong($title, TITLE_MAX)) {
            setFlash('error', 'Give the announcement a title of 2-' . TITLE_MAX . ' characters.');
            setFormOld(['ann_title' => $title, 'ann_body' => $body]);
        } elseif (isTooLong($body, BODY_MAX)) {
            setFlash('error', 'Keep the announcement to ' . BODY_MAX . ' characters or fewer.');
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
$old = takeFormOld();

$list = $pdo->query(
    'SELECT a.*, u.username FROM announcements a LEFT JOIN users u ON u.user_id = a.created_by ORDER BY a.created_at DESC'
)->fetchAll() ?: [];

$pageTitle = 'Announcements';
require_once __DIR__ . '/../layout/header.php';
?>

<div class="container page-shell container--sm">
  <div class="section-header">
    <div>
      <h1 class="section-header__title">Announcements</h1>
    </div>
    <span class="badge badge-blue"><?= count($list) ?> post<?= count($list) === 1 ? '' : 's' ?></span>
  </div>

  <?php renderFlash($flash); ?>

  <div class="card mb-4">
    <h2 class="card-title">New announcement</h2>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
      <input type="hidden" name="post_action" value="create">
      <div class="form-group">
        <label for="ann_title">Title</label>
        <input type="text" id="ann_title" name="ann_title" maxlength="<?= TITLE_MAX ?>" value="<?= sanitise($old['ann_title'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label for="ann_body">Body</label>
        <textarea id="ann_body" name="ann_body" rows="3" maxlength="<?= BODY_MAX ?>"><?= sanitise($old['ann_body'] ?? '') ?></textarea>
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
            <h3 class="entry-list__title"><?= sanitise($a['title']) ?></h3>
            <p class="meta-copy"><?= sanitise($a['username'] ?? '') ?> &middot; <?= sanitise($a['created_at']) ?></p>
            <?php if (!empty($a['body'])): ?>
              <p class="entry-list__body"><?= nl2br(sanitise($a['body'])) ?></p>
            <?php endif; ?>
            <form method="POST" class="mt-3">
              <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
              <input type="hidden" name="post_action" value="delete">
              <input type="hidden" name="ann_id" value="<?= (int)$a['ann_id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this announcement?">Delete</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
