<?php
require_once __DIR__ . '/../includes/bootstrap.php';

requireRole('participant');

$uid = currentUserId();
$cats = getCategories();
$challengeContext = getJoinedChallenge($uid, max(0, (int)($_GET['challenge_id'] ?? $_POST['challenge_id'] ?? 0)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');
    $catId = (int)($_POST['cat_id'] ?? 0);
    $desc = trim((string)($_POST['description'] ?? ''));

    $errors = submitActivity($uid, $catId, $desc, $_FILES['evidence'] ?? null, $challengeContext);

    if (!$errors) {
        setFlash('success', $challengeContext
            ? 'Activity submitted for moderator review. Once approved, it will count toward your challenge automatically.'
            : 'Activity submitted for moderator review.');
    } else {
        foreach ($errors as $message) {
            setFlash('error', $message);
        }
        setFormOld(['cat_id' => $catId, 'description' => $desc]);
    }

    $redirect = '/participant/log_activity.php';
    if ($challengeContext) {
        $redirect .= '?challenge_id=' . (int)$challengeContext['challenge_id'];
    }
    redirectTo($redirect);
}

$flash = takeFlash();
$old = takeFormOld();
$selectedCatId = (int)($old['cat_id'] ?? (int)($challengeContext['cat_id'] ?? 0));

$pageTitle = 'Log activity';
require_once __DIR__ . '/../layout/header.php';
?>

<div class="container page-shell container--xs">
  <div class="section-header">
    <div>
      <h1 class="section-header__title">Log activity</h1>
    </div>
  </div>

  <?php renderFlash($flash); ?>

  <?php if ($challengeContext): ?>
    <div class="card mb-4 card--tinted">
      <h2 class="card-title mb-2">Challenge activity</h2>
      <p class="card-copy mb-2">
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
        <textarea id="description" name="description" rows="4" required
                  minlength="<?= ACTIVITY_DESCRIPTION_MIN ?>" maxlength="<?= ACTIVITY_DESCRIPTION_MAX ?>"
                  data-minlength="<?= ACTIVITY_DESCRIPTION_MIN ?>"
                  placeholder="Describe your eco-friendly action..."><?= sanitise($old['description'] ?? '') ?></textarea>
        <small class="field-hint">
          At least <?= ACTIVITY_DESCRIPTION_MIN ?> characters. Moderators use this to decide whether to approve your points.
        </small>
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

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
