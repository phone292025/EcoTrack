<?php
/**
 * EcoTrack — Challenge management, shared by admins and moderators.
 * moderator/create_challenge.php loads this same page with both roles allowed.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$challengeManagementRoles = $challengeManagementRoles ?? ['admin'];
requireRole(...$challengeManagementRoles);

$pdo = getPDO();
$cats = getCategories();

$challengeForm = [
    'title' => '',
    'description' => '',
    'cat_id' => 0,
    'difficulty' => 'easy',
    'points' => 10,
    'target_count' => 1,
    'start_date' => '',
    'end_date' => '',
];
$expandedChallengeId = max(0, (int)($_GET['edit'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');

    // Housekeeping on a write path, never on a render: anything whose end date
    // has passed is marked closed so the list reflects reality.
    $expired = closeExpiredChallenges();
    if ($expired > 0) {
        setFlash('success', $expired . ' expired challenge' . ($expired === 1 ? '' : 's') . ' closed automatically.');
    }

    $action = $_POST['action'] ?? '';
    $challengeId = (int)($_POST['challenge_id'] ?? 0);

    if ($action === 'create') {
        $check = validateChallengeInput($_POST, false);

        if (!$check['ok']) {
            setFlash('error', $check['message']);
            setFormOld(array_intersect_key($_POST, $challengeForm));
        } else {
            $v = $check['values'];
            $pdo->prepare(
                'INSERT INTO challenges (title, description, cat_id, difficulty, points, target_count, start_date, end_date, created_by, status)
                 VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ""), NULLIF(?, ""), ?, "active")'
            )->execute([
                $v['title'], $v['description'], $v['cat_id'] > 0 ? $v['cat_id'] : null, $v['difficulty'],
                $v['points'], $v['target_count'], $v['start_date'], $v['end_date'], currentUserId(),
            ]);

            setFlash('success', 'Challenge created and published.');
        }
    } elseif ($action === 'update') {
        $check = validateChallengeInput($_POST, true);

        if ($challengeId <= 0) {
            setFlash('error', 'Invalid challenge selected.');
        } elseif (!$check['ok']) {
            setFlash('error', $check['message']);
        } else {
            $v = $check['values'];
            $pdo->prepare(
                'UPDATE challenges
                 SET title = ?, description = ?, cat_id = ?, difficulty = ?, points = ?,
                     target_count = ?, start_date = NULLIF(?, ""), end_date = NULLIF(?, ""), status = ?
                 WHERE challenge_id = ?'
            )->execute([
                $v['title'], $v['description'], $v['cat_id'] > 0 ? $v['cat_id'] : null, $v['difficulty'], $v['points'],
                $v['target_count'], $v['start_date'], $v['end_date'], $v['status'], $challengeId,
            ]);

            setFlash('success', 'Challenge updated successfully.');
        }
    } elseif ($action === 'delete') {
        if ($challengeId > 0) {
            $pdo->prepare('DELETE FROM challenges WHERE challenge_id = ?')->execute([$challengeId]);
            setFlash('success', 'Challenge deleted.');
        } else {
            setFlash('error', 'Invalid challenge selected.');
        }
    }

    // Redirect so refreshing cannot repeat the submission.
    redirectToSelf($action === 'update' && $challengeId > 0 ? 'edit=' . $challengeId : '');
}

$flash = takeFlash();
$old = takeFormOld();
if ($old) {
    $challengeForm = array_merge($challengeForm, $old);
}

$list = $pdo->query(
    'SELECT c.*, cat.name AS cat_name, u.username AS created_by_name,
            (SELECT COUNT(*) FROM challenge_participants cp WHERE cp.challenge_id = c.challenge_id) AS joined_count
     FROM challenges c
     LEFT JOIN categories cat ON cat.cat_id = c.cat_id
     LEFT JOIN users u ON u.user_id = c.created_by
     ORDER BY c.challenge_id DESC'
)->fetchAll() ?: [];

$pageTitle = 'Challenges';
require_once __DIR__ . '/../layout/header.php';
?>

<div class="container page-shell container--lg">
  <div class="section-header">
    <div>
      <h1 class="section-header__title">Challenge management</h1>
    </div>
    <span class="badge badge-blue"><?= count($list) ?> challenge<?= count($list) === 1 ? '' : 's' ?></span>
  </div>

  <?php renderFlash($flash); ?>

  <div class="challenge-layout">
    <div class="card">
      <h2 class="card-title">Create challenge</h2>
      <form method="POST" class="form-card">
        <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
        <input type="hidden" name="action" value="create">

        <div class="form-group mb-0">
          <label for="create_title">Title</label>
          <input type="text" id="create_title" name="title" maxlength="<?= CHALLENGE_TITLE_MAX ?>" required value="<?= sanitise($challengeForm['title']) ?>">
        </div>

        <div class="form-group mb-0">
          <label for="create_description">Description</label>
          <textarea id="create_description" name="description" rows="4"><?= sanitise($challengeForm['description']) ?></textarea>
        </div>

        <div class="form-group mb-0">
          <label for="create_cat_id">Category</label>
          <select id="create_cat_id" name="cat_id">
            <option value="0">No category</option>
            <?php foreach ($cats as $cat): ?>
              <option value="<?= (int)$cat['cat_id'] ?>" <?= (int)$challengeForm['cat_id'] === (int)$cat['cat_id'] ? 'selected' : '' ?>>
                <?= sanitise($cat['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-grid-3">
          <div class="form-group mb-0">
            <label for="create_difficulty">Difficulty</label>
            <select id="create_difficulty" name="difficulty">
              <option value="easy" <?= $challengeForm['difficulty'] === 'easy' ? 'selected' : '' ?>>Easy</option>
              <option value="medium" <?= $challengeForm['difficulty'] === 'medium' ? 'selected' : '' ?>>Medium</option>
              <option value="hard" <?= $challengeForm['difficulty'] === 'hard' ? 'selected' : '' ?>>Hard</option>
            </select>
          </div>
          <div class="form-group mb-0">
            <label for="create_points">Points</label>
            <input type="number" id="create_points" name="points" min="1" max="<?= CHALLENGE_POINTS_MAX ?>" value="<?= sanitise($challengeForm['points']) ?>">
          </div>

          <div class="form-group">
            <label for="create_target_count">Approved logs required</label>
            <input type="number" id="create_target_count" name="target_count" min="1" max="<?= CHALLENGE_TARGET_MAX ?>" value="<?= sanitise($challengeForm['target_count']) ?>">
            <small class="field-hint">How many approved activities complete this challenge.</small>
          </div>
        </div>

        <div class="form-grid-2">
          <div class="form-group mb-0">
            <label for="create_start_date">Start date</label>
            <input type="date" id="create_start_date" name="start_date" value="<?= sanitise($challengeForm['start_date']) ?>">
          </div>
          <div class="form-group mb-0">
            <label for="create_end_date">End date</label>
            <input type="date" id="create_end_date" name="end_date" value="<?= sanitise($challengeForm['end_date']) ?>">
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Create challenge</button>
      </form>
    </div>

    <div class="panel-stack">
      <?php if (empty($list)): ?>
        <div class="card empty-state">
          <h2 class="card-title empty-state__title">No challenges yet</h2>
          <p class="empty-state__text">Create your first challenge on the left to start populating the platform.</p>
        </div>
      <?php else: ?>
        <?php foreach ($list as $challenge): ?>
          <details class="card challenge-admin-item"<?= $expandedChallengeId === (int)$challenge['challenge_id'] ? ' open' : '' ?>>
            <summary class="challenge-admin-summary">
              <div class="challenge-admin-summary__identity">
                <div class="challenge-admin-summary__title-row">
                  <h2 class="card-title mb-0"><?= sanitise($challenge['title']) ?></h2>
                  <?php
                    // An "active" row whose end date has passed is over. Label it
                    // that way immediately, rather than waiting for the sweep.
                    $hasExpired = $challenge['status'] === 'active'
                        && !empty($challenge['end_date'])
                        && $challenge['end_date'] < dbToday();

                    if ($hasExpired) {
                        $statusLabel = 'expired';
                        $statusClass = 'badge-red';
                    } else {
                        $statusLabel = $challenge['status'];
                        $statusClass = $challenge['status'] === 'active'
                            ? 'badge-green'
                            : ($challenge['status'] === 'closed' ? 'badge-grey' : 'badge-amber');
                    }
                  ?>
                  <span class="badge challenge-admin-card__status <?= $statusClass ?>">
                    <?= sanitise($statusLabel) ?>
                  </span>
                </div>
                <p class="meta-copy">
                  Created by <?= sanitise($challenge['created_by_name'] ?? 'System') ?>
                  &middot; <?= (int)$challenge['joined_count'] ?> joined
                  <?php if (!empty($challenge['cat_name'])): ?> &middot; <?= sanitise($challenge['cat_name']) ?><?php endif; ?>
                </p>
                <div class="challenge-admin-summary__chips">
                  <span class="inline-pill-note"><?= ucfirst((string)$challenge['difficulty']) ?></span>
                  <span class="inline-pill-note"><?= (int)$challenge['points'] ?> pts</span>
                  <?php if (!empty($challenge['start_date']) || !empty($challenge['end_date'])): ?>
                    <span class="inline-pill-note">
                      <?= sanitise($challenge['start_date'] ?: 'No start') ?> to <?= sanitise($challenge['end_date'] ?: 'No end') ?>
                    </span>
                  <?php endif; ?>
                </div>
              </div>

              <div class="challenge-admin-summary__actions">
                <span class="challenge-admin-summary__toggle">Edit challenge</span>
              </div>
            </summary>

            <div class="challenge-admin-item__panel">
              <form method="POST" class="form-card">
                <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="challenge_id" value="<?= (int)$challenge['challenge_id'] ?>">

                <div class="form-grid-wide-narrow">
                  <div class="form-group mb-0">
                    <label for="edit_title_<?= (int)$challenge['challenge_id'] ?>">Title</label>
                    <input type="text" id="edit_title_<?= (int)$challenge['challenge_id'] ?>" name="title" maxlength="<?= CHALLENGE_TITLE_MAX ?>" value="<?= sanitise($challenge['title']) ?>">
                  </div>
                  <div class="form-group mb-0">
                    <label for="edit_status_<?= (int)$challenge['challenge_id'] ?>">Status</label>
                    <select id="edit_status_<?= (int)$challenge['challenge_id'] ?>" name="status">
                      <option value="active" <?= $challenge['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                      <option value="closed" <?= $challenge['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                    </select>
                  </div>
                </div>

                <div class="form-group mb-0">
                  <label for="edit_description_<?= (int)$challenge['challenge_id'] ?>">Description</label>
                  <textarea id="edit_description_<?= (int)$challenge['challenge_id'] ?>" name="description" rows="3"><?= sanitise($challenge['description'] ?? '') ?></textarea>
                </div>

                <div class="form-grid-5">
                  <div class="form-group mb-0">
                    <label for="edit_cat_id_<?= (int)$challenge['challenge_id'] ?>">Category</label>
                    <select id="edit_cat_id_<?= (int)$challenge['challenge_id'] ?>" name="cat_id">
                      <option value="0">No category</option>
                      <?php foreach ($cats as $cat): ?>
                        <option value="<?= (int)$cat['cat_id'] ?>" <?= (int)($challenge['cat_id'] ?? 0) === (int)$cat['cat_id'] ? 'selected' : '' ?>>
                          <?= sanitise($cat['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-group mb-0">
                    <label for="edit_difficulty_<?= (int)$challenge['challenge_id'] ?>">Difficulty</label>
                    <select id="edit_difficulty_<?= (int)$challenge['challenge_id'] ?>" name="difficulty">
                      <option value="easy" <?= $challenge['difficulty'] === 'easy' ? 'selected' : '' ?>>Easy</option>
                      <option value="medium" <?= $challenge['difficulty'] === 'medium' ? 'selected' : '' ?>>Medium</option>
                      <option value="hard" <?= $challenge['difficulty'] === 'hard' ? 'selected' : '' ?>>Hard</option>
                    </select>
                  </div>
                  <div class="form-group mb-0">
                    <label for="edit_points_<?= (int)$challenge['challenge_id'] ?>">Points</label>
                    <input type="number" id="edit_points_<?= (int)$challenge['challenge_id'] ?>" name="points" min="1" max="<?= CHALLENGE_POINTS_MAX ?>" value="<?= (int)$challenge['points'] ?>">
                  </div>

                  <div class="form-group">
                    <label for="edit_target_count_<?= (int)$challenge['challenge_id'] ?>">Approved logs required</label>
                    <input type="number" id="edit_target_count_<?= (int)$challenge['challenge_id'] ?>" name="target_count" min="1" max="<?= CHALLENGE_TARGET_MAX ?>" value="<?= max(1, (int)($challenge['target_count'] ?? 1)) ?>">
                  </div>
                  <div class="form-group mb-0">
                    <label for="edit_start_date_<?= (int)$challenge['challenge_id'] ?>">Start</label>
                    <input type="date" id="edit_start_date_<?= (int)$challenge['challenge_id'] ?>" name="start_date" value="<?= sanitise($challenge['start_date'] ?? '') ?>">
                  </div>
                  <div class="form-group mb-0">
                    <label for="edit_end_date_<?= (int)$challenge['challenge_id'] ?>">End</label>
                    <input type="date" id="edit_end_date_<?= (int)$challenge['challenge_id'] ?>" name="end_date" value="<?= sanitise($challenge['end_date'] ?? '') ?>">
                  </div>
                </div>

                <div class="actions-row">
                  <p class="meta-copy">Challenge #<?= (int)$challenge['challenge_id'] ?> &middot; Created <?= sanitise((string)($challenge['created_at'] ?? '')) ?></p>
                  <div class="actions-row__group">
                    <button type="submit" class="btn btn-sm btn-primary">Save changes</button>
                    <button type="submit" class="btn btn-sm btn-danger" name="action" value="delete" data-confirm="Delete this challenge?">Delete</button>
                  </div>
                </div>
              </form>
            </div>
          </details>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
