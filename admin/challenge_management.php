<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$challengeManagementRoles = $challengeManagementRoles ?? ['admin'];
requireRole(...$challengeManagementRoles);

$pdo = getPDO();
$categoryRows = $pdo->query('SELECT cat_id, name FROM categories ORDER BY name ASC, cat_id ASC')->fetchAll() ?: [];
$cats = [];
$catCanonicalMap = [0 => 0];
$catFirstByName = [];

foreach ($categoryRows as $row) {
    $catId = (int)($row['cat_id'] ?? 0);
    $name = trim((string)($row['name'] ?? ''));

    if ($catId <= 0 || $name === '') {
        continue;
    }

    if (!isset($catFirstByName[$name])) {
        $catFirstByName[$name] = $catId;
        $cats[] = [
            'cat_id' => $catId,
            'name' => $name,
        ];
    }

    $catCanonicalMap[$catId] = $catFirstByName[$name];
}

$resolveCatId = static function (int $catId) use ($catCanonicalMap): int {
    return $catCanonicalMap[$catId] ?? $catId;
};

$challengeForm = [
    'title' => '',
    'description' => '',
    'cat_id' => '0',
    'difficulty' => 'easy',
    'points' => '10',
    'start_date' => '',
    'end_date' => '',
];
$err = '';
$ok = '';
$expandedChallengeId = max(0, (int)($_GET['edit'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $challengeForm = [
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'cat_id' => (string)((int)($_POST['cat_id'] ?? 0)),
            'difficulty' => $_POST['difficulty'] ?? 'easy',
            'points' => (string)((int)($_POST['points'] ?? 10)),
            'start_date' => trim($_POST['start_date'] ?? ''),
            'end_date' => trim($_POST['end_date'] ?? ''),
        ];

        $title = $challengeForm['title'];
        $body = $challengeForm['description'];
        $catId = (int)$challengeForm['cat_id'];
        $difficulty = $challengeForm['difficulty'];
        $points = (int)$challengeForm['points'];
        $startDate = $challengeForm['start_date'];
        $endDate = $challengeForm['end_date'];

        if (strlen($title) < 3) {
            $err = 'Challenge title must be at least 3 characters.';
        } elseif (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
            $err = 'Please choose a valid difficulty.';
        } elseif ($points < 1) {
            $err = 'Challenge points must be at least 1.';
        } elseif ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
            $err = 'End date must be on or after the start date.';
        } else {
            $catValue = $catId > 0 ? $catId : null;
            $pdo->prepare(
                'INSERT INTO challenges (title, description, cat_id, difficulty, points, start_date, end_date, created_by, status)
                 VALUES (?, ?, ?, ?, ?, NULLIF(?, ""), NULLIF(?, ""), ?, "active")'
            )->execute([$title, $body, $catValue, $difficulty, $points, $startDate, $endDate, currentUserId()]);

            $ok = 'Challenge created and published.';
            $challengeForm = [
                'title' => '',
                'description' => '',
                'cat_id' => '0',
                'difficulty' => 'easy',
                'points' => '10',
                'start_date' => '',
                'end_date' => '',
            ];
        }
    } elseif ($action === 'update') {
        $challengeId = (int)($_POST['challenge_id'] ?? 0);
        $expandedChallengeId = $challengeId;
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['description'] ?? '');
        $catId = (int)($_POST['cat_id'] ?? 0);
        $difficulty = $_POST['difficulty'] ?? 'easy';
        $points = (int)($_POST['points'] ?? 10);
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if ($challengeId <= 0) {
            $err = 'Invalid challenge selected.';
        } elseif (strlen($title) < 3) {
            $err = 'Challenge title must be at least 3 characters.';
        } elseif (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
            $err = 'Please choose a valid difficulty.';
        } elseif (!in_array($status, ['active', 'closed'], true)) {
            $err = 'Please choose a valid status.';
        } elseif ($points < 1) {
            $err = 'Challenge points must be at least 1.';
        } elseif ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
            $err = 'End date must be on or after the start date.';
        } else {
            $catValue = $catId > 0 ? $catId : null;
            $pdo->prepare(
                'UPDATE challenges
                 SET title = ?, description = ?, cat_id = ?, difficulty = ?, points = ?,
                     start_date = NULLIF(?, ""), end_date = NULLIF(?, ""), status = ?
                 WHERE challenge_id = ?'
            )->execute([$title, $body, $catValue, $difficulty, $points, $startDate, $endDate, $status, $challengeId]);

            $ok = 'Challenge updated successfully.';
        }
    } elseif ($action === 'delete') {
        $challengeId = (int)($_POST['challenge_id'] ?? 0);
        if ($challengeId > 0) {
            $pdo->prepare('DELETE FROM challenges WHERE challenge_id = ?')->execute([$challengeId]);
            $ok = 'Challenge deleted.';
        } else {
            $err = 'Invalid challenge selected.';
        }
    }
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
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-shell" style="max-width:1120px;">
  <div class="section-header">
    <div>
      <h1 class="section-header__title">Challenge management</h1>
      <p class="section-header__text">Create, publish, update, and retire sustainability challenges from one place.</p>
    </div>
    <span class="badge badge-blue"><?= count($list) ?> challenge<?= count($list) === 1 ? '' : 's' ?></span>
  </div>

  <?php if ($err): ?><div class="flash-message flash-error" role="alert"><?= sanitise($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="flash-message flash-success" role="status"><?= sanitise($ok) ?></div><?php endif; ?>

  <div class="challenge-layout">
    <div class="card">
      <h2 class="card-title">Create challenge</h2>
      <form method="POST" class="form-card">
        <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
        <input type="hidden" name="action" value="create">

        <div class="form-group" style="margin-bottom:0;">
          <label for="create_title">Title</label>
          <input type="text" id="create_title" name="title" maxlength="150" required value="<?= sanitise($challengeForm['title']) ?>">
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label for="create_description">Description</label>
          <textarea id="create_description" name="description" rows="4"><?= sanitise($challengeForm['description']) ?></textarea>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label for="create_cat_id">Category</label>
          <select id="create_cat_id" name="cat_id">
            <option value="0">No category</option>
            <?php foreach ($cats as $cat): ?>
              <option value="<?= (int)$cat['cat_id'] ?>" <?= $resolveCatId((int)$challengeForm['cat_id']) === (int)$cat['cat_id'] ? 'selected' : '' ?>>
                <?= sanitise($cat['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-grid-3">
          <div class="form-group" style="margin-bottom:0;">
            <label for="create_difficulty">Difficulty</label>
            <select id="create_difficulty" name="difficulty">
              <option value="easy" <?= $challengeForm['difficulty'] === 'easy' ? 'selected' : '' ?>>Easy</option>
              <option value="medium" <?= $challengeForm['difficulty'] === 'medium' ? 'selected' : '' ?>>Medium</option>
              <option value="hard" <?= $challengeForm['difficulty'] === 'hard' ? 'selected' : '' ?>>Hard</option>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label for="create_points">Points</label>
            <input type="number" id="create_points" name="points" min="1" max="9999" value="<?= sanitise($challengeForm['points']) ?>">
          </div>
        </div>

        <div class="form-grid-2">
          <div class="form-group" style="margin-bottom:0;">
            <label for="create_start_date">Start date</label>
            <input type="date" id="create_start_date" name="start_date" value="<?= sanitise($challengeForm['start_date']) ?>">
          </div>
          <div class="form-group" style="margin-bottom:0;">
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
                  <h2 class="card-title" style="margin-bottom:0;"><?= sanitise($challenge['title']) ?></h2>
                  <span class="badge challenge-admin-card__status <?= $challenge['status'] === 'active' ? 'badge-green' : ($challenge['status'] === 'closed' ? 'badge-grey' : 'badge-amber') ?>">
                    <?= sanitise($challenge['status']) ?>
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
                  <div class="form-group" style="margin-bottom:0;">
                    <label>Title</label>
                    <input type="text" name="title" maxlength="150" value="<?= sanitise($challenge['title']) ?>">
                  </div>
                  <div class="form-group" style="margin-bottom:0;">
                    <label>Status</label>
                    <select name="status">
                      <option value="active" <?= $challenge['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                      <option value="closed" <?= $challenge['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                    </select>
                  </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                  <label>Description</label>
                  <textarea name="description" rows="3"><?= sanitise($challenge['description'] ?? '') ?></textarea>
                </div>

                <div class="form-grid-5">
                  <div class="form-group" style="margin-bottom:0;">
                    <label>Category</label>
                    <select name="cat_id">
                      <option value="0">No category</option>
                      <?php foreach ($cats as $cat): ?>
                        <option value="<?= (int)$cat['cat_id'] ?>" <?= $resolveCatId((int)($challenge['cat_id'] ?? 0)) === (int)$cat['cat_id'] ? 'selected' : '' ?>>
                          <?= sanitise($cat['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-group" style="margin-bottom:0;">
                    <label>Difficulty</label>
                    <select name="difficulty">
                      <option value="easy" <?= $challenge['difficulty'] === 'easy' ? 'selected' : '' ?>>Easy</option>
                      <option value="medium" <?= $challenge['difficulty'] === 'medium' ? 'selected' : '' ?>>Medium</option>
                      <option value="hard" <?= $challenge['difficulty'] === 'hard' ? 'selected' : '' ?>>Hard</option>
                    </select>
                  </div>
                  <div class="form-group" style="margin-bottom:0;">
                    <label>Points</label>
                    <input type="number" name="points" min="1" max="9999" value="<?= (int)$challenge['points'] ?>">
                  </div>
                  <div class="form-group" style="margin-bottom:0;">
                    <label>Start</label>
                    <input type="date" name="start_date" value="<?= sanitise($challenge['start_date'] ?? '') ?>">
                  </div>
                  <div class="form-group" style="margin-bottom:0;">
                    <label>End</label>
                    <input type="date" name="end_date" value="<?= sanitise($challenge['end_date'] ?? '') ?>">
                  </div>
                </div>

                <div class="actions-row">
                  <p class="meta-copy">Challenge #<?= (int)$challenge['challenge_id'] ?> &middot; Created <?= sanitise((string)($challenge['created_at'] ?? '')) ?></p>
                  <div class="actions-row__group">
                    <button type="submit" class="btn btn-sm btn-primary">Save changes</button>
                    <button type="submit" class="btn btn-sm btn-danger" name="action" value="delete" onclick="return confirm('Delete this challenge?');">Delete</button>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
