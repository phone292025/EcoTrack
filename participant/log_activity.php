<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('participant');

$pdo = getPDO();
$cats = $pdo->query('SELECT cat_id, name FROM categories ORDER BY cat_id')->fetchAll();
$challengeId = max(0, (int)($_GET['challenge_id'] ?? $_POST['challenge_id'] ?? 0));
$challengeContext = null;

if ($challengeId > 0) {
    $challengeStmt = $pdo->prepare(
        'SELECT c.challenge_id, c.title, c.description, c.cat_id, c.start_date, c.end_date,
                cat.name AS cat_name, cp.completed
         FROM challenge_participants cp
         JOIN challenges c ON c.challenge_id = cp.challenge_id
         LEFT JOIN categories cat ON cat.cat_id = c.cat_id
         WHERE cp.challenge_id = ? AND cp.user_id = ?
         LIMIT 1'
    );
    $challengeStmt->execute([$challengeId, currentUserId()]);
    $challengeContext = $challengeStmt->fetch() ?: null;
}

$errors = [];
$success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

$selectedCatId = (int)($_POST['cat_id'] ?? (int)($challengeContext['cat_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');
    $catId = (int)($_POST['cat_id'] ?? 0);
    $desc = trim($_POST['description'] ?? '');

    if ($catId <= 0) {
        $errors[] = 'Choose a category.';
    }
    if (strlen($desc) < 5) {
        $errors[] = 'Description must be at least 5 characters.';
    }
    if ($challengeContext && !empty($challengeContext['cat_id']) && $catId !== (int)$challengeContext['cat_id']) {
        $errors[] = 'This challenge must be submitted under the "' . $challengeContext['cat_name'] . '" category.';
    }

    $evidence = null;
    if (!empty($_FILES['evidence']['name']) && ($_FILES['evidence']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $evidence = handleFileUpload($_FILES['evidence']);
        if ($evidence === null) {
            $errors[] = 'Evidence file could not be uploaded (type/size).';
        }
    }

    if (empty($errors)) {
        $pts = 10;
        $pdo->prepare(
            'INSERT INTO activity_logs (user_id, cat_id, description, evidence, points, status)
             VALUES (?, ?, ?, ?, ?, "pending")'
        )->execute([currentUserId(), $catId, $desc, $evidence, $pts]);

        $_SESSION['flash_success'] = $challengeContext
            ? 'Activity submitted for moderator review. Once approved, it will count toward your challenge automatically.'
            : 'Activity submitted for moderator review.';
        $redirect = BASE_URL . '/participant/log_activity.php';
        if ($challengeContext) {
            $redirect .= '?challenge_id=' . (int)$challengeContext['challenge_id'];
        }
        header('Location: ' . $redirect);
        exit;
    }
}

$pageTitle = 'Log activity';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-shell" style="max-width:560px;">
  <div class="section-header">
    <div>
      <h1 class="section-header__title">Log activity</h1>
      <p class="section-header__text">Submit your eco-friendly action with a short description and optional photo evidence for review.</p>
    </div>
  </div>

  <?php foreach ($errors as $e): ?>
    <div class="flash-message flash-error" role="alert"><?= sanitise($e) ?></div>
  <?php endforeach; ?>
  <?php if ($success): ?>
    <div class="flash-message flash-success" role="status"><?= sanitise($success) ?></div>
  <?php endif; ?>

  <?php if ($challengeContext): ?>
    <div class="card" style="margin-bottom:var(--space-4); background:#f8fbf9;">
      <h2 class="card-title" style="margin-bottom:var(--space-2);">Challenge activity</h2>
      <p class="card-copy" style="margin-bottom:var(--space-2);">
        You are submitting activity for <strong><?= sanitise($challengeContext['title']) ?></strong>.
        <?php if (!empty($challengeContext['cat_name'])): ?>
          Use the <strong><?= sanitise($challengeContext['cat_name']) ?></strong> category so the submission matches this challenge.
        <?php else: ?>
          Any approved eco activity after joining can count for this challenge.
        <?php endif; ?>
      </p>
      <?php if (!empty($challengeContext['start_date']) || !empty($challengeContext['end_date'])): ?>
        <p class="meta-copy">
          Valid window:
          <?= !empty($challengeContext['start_date']) ? sanitise((string)$challengeContext['start_date']) : 'Any start' ?>
          &middot;
          <?= !empty($challengeContext['end_date']) ? sanitise((string)$challengeContext['end_date']) : 'No end date' ?>
        </p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" enctype="multipart/form-data" data-validate="log_activity" novalidate>
      <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
      <?php if ($challengeContext): ?>
        <input type="hidden" name="challenge_id" value="<?= (int)$challengeContext['challenge_id'] ?>">
      <?php endif; ?>

      <div class="form-group">
        <label for="cat_id">Category</label>
        <select id="cat_id" name="cat_id" required>
          <option value="">Select a category</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['cat_id'] ?>" <?= $selectedCatId === (int)$c['cat_id'] ? 'selected' : '' ?>>
              <?= sanitise($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="description">What did you do?</label>
        <textarea id="description" name="description" rows="4" required maxlength="2000"
                  placeholder="Describe your eco-friendly action..."><?= sanitise($_POST['description'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label for="evidence">Photo evidence (optional)</label>
        <input type="file" id="evidence" name="evidence" accept="image/jpeg,image/png,image/gif,image/webp">
        <div class="upload-preview" id="evidencePreview" hidden>
          <div class="upload-preview__header">
            <span class="upload-preview__label">Selected image</span>
            <span class="upload-preview__meta" id="evidencePreviewMeta">No file selected.</span>
          </div>
          <div class="upload-preview__frame">
            <img id="evidencePreviewImage" src="" alt="Preview of the selected evidence image">
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn-primary btn-block">Submit for review</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
