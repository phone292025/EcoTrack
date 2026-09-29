<?php
require_once __DIR__ . '/../includes/bootstrap.php';

requireRole('admin');

$pdo = getPDO();
$badgeForm = [
    'name' => '',
    'description' => '',
    'criteria' => '',
];

/**
 * Check a badge form. Returns an error message, or null when it is fine.
 */
function badgeInputProblem(string $name, string $description, string $criteria): ?string
{
    if (mb_strlen($name) < 2 || isTooLong($name, BADGE_NAME_MAX)) {
        return 'Badge name must be 2-' . BADGE_NAME_MAX . ' characters.';
    }
    if (isTooLong($description, BODY_MAX)) {
        return 'Description must be ' . BODY_MAX . ' characters or fewer.';
    }
    if (!isValidBadgeCriteria($criteria)) {
        return 'Criteria must be points>=N, streak>=N, logs>=N, goal_achieved, or left empty for a badge you award by hand.';
    }
    return null;
}

/** Tell the admin how many people a new or changed rule reached straight away. */
function flashBackfill(int $badgeId): void
{
    $awarded = awardBadgeToQualifiedUsers($badgeId);
    if ($awarded > 0) {
        setFlash('success', $awarded . ' participant' . ($awarded === 1 ? '' : 's')
            . ' already met the criteria and received the badge.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');
    $action = $_POST['action'] ?? 'create';
    $badgeId = (int)($_POST['badge_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $criteria = trim((string)($_POST['criteria'] ?? ''));

    if ($action === 'create') {
        if (($problem = badgeInputProblem($name, $description, $criteria)) !== null) {
            setFlash('error', $problem);
            setFormOld(['name' => $name, 'description' => $description, 'criteria' => $criteria]);
        } else {
            $pdo->prepare(
                'INSERT INTO badges (name, description, icon, criteria, created_by)
                 VALUES (?, ?, NULL, NULLIF(?, ""), ?)'
            )->execute([$name, $description, $criteria, currentUserId()]);

            setFlash('success', 'Badge created.');
            flashBackfill((int)$pdo->lastInsertId());
        }
    } elseif ($action === 'update') {
        if ($badgeId <= 0) {
            setFlash('error', 'Invalid badge selected.');
        } elseif (($problem = badgeInputProblem($name, $description, $criteria)) !== null) {
            setFlash('error', $problem);
        } else {
            $pdo->prepare(
                'UPDATE badges
                 SET name = ?, description = ?, criteria = NULLIF(?, "")
                 WHERE badge_id = ?'
            )->execute([$name, $description, $criteria, $badgeId]);
            setFlash('success', 'Badge updated.');
            flashBackfill($badgeId);
        }
    } elseif ($action === 'delete') {
        if ($badgeId > 0) {
            $pdo->prepare('DELETE FROM badges WHERE badge_id = ?')->execute([$badgeId]);
            setFlash('success', 'Badge deleted.');
        } else {
            setFlash('error', 'Invalid badge selected.');
        }
    } elseif ($action === 'award' || $action === 'revoke') {
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE (username = ? OR email = ?) AND role = "participant"');
        $stmt->execute([trim((string)($_POST['username'] ?? '')), trim((string)($_POST['username'] ?? ''))]);
        $userId = (int)$stmt->fetchColumn();

        $badgeStmt = $pdo->prepare('SELECT name FROM badges WHERE badge_id = ?');
        $badgeStmt->execute([$badgeId]);
        $badgeName = (string)$badgeStmt->fetchColumn();

        if ($badgeName === '') {
            setFlash('error', 'Choose a badge.');
        } elseif ($userId === 0) {
            setFlash('error', 'No participant has that username or email.');
        } elseif ($action === 'award') {
            setFlash(
                'success',
                grantBadge($userId, $badgeId) ? '"' . $badgeName . '" awarded.' : 'That participant already has "' . $badgeName . '".'
            );
        } else {
            flashResult(takeBackBadge($userId, $badgeId));
        }
    }

    // Redirect so refreshing cannot repeat the submission.
    redirectToSelf($_SERVER['QUERY_STRING'] ?? '');
}

$flash = takeFlash();
$old = takeFormOld();
if ($old) {
    $badgeForm = array_merge($badgeForm, $old);
}

$badges = $pdo->query(
    'SELECT b.*, u.username AS created_by_name,
            (SELECT COUNT(*) FROM user_badges ub WHERE ub.badge_id = b.badge_id) AS holder_count
     FROM badges b
     LEFT JOIN users u ON u.user_id = b.created_by
     ORDER BY b.badge_id ASC'
)->fetchAll() ?: [];

$badgeCount = count($badges);
$ruleCount = 0;
$manualCount = 0;

foreach ($badges as $badge) {
    $hasCriteria = trim((string)($badge['criteria'] ?? '')) !== '';

    if ($hasCriteria) {
        $ruleCount++;
    } else {
        $manualCount++;
    }
}

$editingBadgeId = max(0, (int)($_GET['edit_badge'] ?? 0));
$editingBadge = null;
foreach ($badges as $badge) {
    if ((int)$badge['badge_id'] === $editingBadgeId) {
        $editingBadge = $badge;
        break;
    }
}

$buildBadgesManagementUrl = static function (array $overrides = []) use ($editingBadgeId): string {
    $params = [
        'edit_badge' => $editingBadgeId,
    ];

    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }

    if (($params['edit_badge'] ?? 0) <= 0) {
        unset($params['edit_badge']);
    }

    $query = http_build_query($params);
    return 'badges_management.php' . ($query ? '?' . $query : '');
};

$pageTitle = 'Badges';
require_once __DIR__ . '/../layout/header.php';
?>

<div class="container page-shell reward-admin-shell badge-admin-shell">
  <div class="section-header reward-admin-header">
    <div>
      <h1 class="section-header__title">Badge management</h1>
    </div>
    <span class="badge badge-blue"><?= $badgeCount ?> badge<?= $badgeCount === 1 ? '' : 's' ?></span>
  </div>

  <section class="reward-admin-summary-grid">
    <article class="card reward-summary-card">
      <span class="reward-summary-card__label">Total badges</span>
      <strong class="reward-summary-card__value"><?= $badgeCount ?></strong>
    </article>
    <article class="card reward-summary-card">
      <span class="reward-summary-card__label">Auto rules</span>
      <strong class="reward-summary-card__value"><?= $ruleCount ?></strong>
    </article>
    <article class="card reward-summary-card">
      <span class="reward-summary-card__label">Manual badges</span>
      <strong class="reward-summary-card__value"><?= $manualCount ?></strong>
    </article>
  </section>

  <?php renderFlash($flash); ?>

  <section class="card reward-admin-studio">
    <div class="reward-admin-studio-layout">
      <div class="reward-admin-panel__intro reward-admin-panel__intro--compact">
        <span class="badge badge-blue">New badge</span>
        <h2 class="card-title">Create a badge rule</h2>
        <div class="reward-admin-panel__meta">
          <span class="inline-pill-note"><?= $ruleCount ?> auto</span>
          <span class="inline-pill-note"><?= $manualCount ?> manual</span>
        </div>
      </div>

      <form method="POST" class="reward-admin-form">
        <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
        <input type="hidden" name="action" value="create">

        <div class="reward-admin-form-grid badge-admin-form-grid">
          <div class="form-group reward-admin-form-group reward-admin-form-group--name">
            <label for="create_badge_name">Badge name</label>
            <input type="text" id="create_badge_name" name="name" maxlength="<?= BADGE_NAME_MAX ?>" value="<?= sanitise($badgeForm['name']) ?>" required placeholder="Eco Champion">
          </div>

          <div class="form-group reward-admin-form-group">
            <label for="create_badge_criteria">Criteria</label>
            <input type="text" id="create_badge_criteria" name="criteria" value="<?= sanitise($badgeForm['criteria']) ?>" placeholder="points>=100">
          </div>

          <div class="form-group reward-admin-form-group reward-admin-form-group--description">
            <label for="create_badge_description">Description</label>
            <textarea id="create_badge_description" name="description" rows="3" placeholder="Explain what the participant needs to do to earn this badge."><?= sanitise($badgeForm['description']) ?></textarea>
          </div>
        </div>

        <div class="reward-admin-form-actions">
          <p class="badge-admin-form-note">Examples: <code>points&gt;=100</code>, <code>streak&gt;=7</code>, <code>logs&gt;=1</code>, <code>goal_achieved</code>.</p>
          <button type="submit" class="btn btn-primary">Add badge</button>
        </div>
      </form>
    </div>
  </section>

  <?php if (!empty($badges)): ?>
    <section class="card mb-4">
      <h2 class="card-title">Award or take back a badge</h2>
      <p class="card-copy">For badges with no criteria, or to correct a mistake. An automatic badge cannot be taken back while the participant still meets its rule, because it would be awarded again on their next points change.</p>
      <form method="POST" class="badge-award-form mt-3">
        <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
        <div class="form-group mb-0">
          <label for="award_badge_id">Badge</label>
          <select id="award_badge_id" name="badge_id" required>
            <?php foreach ($badges as $badge): ?>
              <option value="<?= (int)$badge['badge_id'] ?>"><?= sanitise($badge['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group mb-0">
          <label for="award_username">Participant username or email</label>
          <input type="text" id="award_username" name="username" maxlength="<?= EMAIL_MAX ?>" required>
        </div>
        <div class="inline-actions">
          <button type="submit" name="action" value="award" class="btn btn-primary">Award</button>
          <button type="submit" name="action" value="revoke" class="btn btn-outline">Take back</button>
        </div>
      </form>
    </section>
  <?php endif; ?>

  <section class="reward-admin-board">
    <?php if (empty($badges)): ?>
      <div class="card empty-state">
        <h2 class="card-title empty-state__title">No badges yet</h2>
        <p class="empty-state__text">Create your first badge from the studio above.</p>
      </div>
    <?php else: ?>
      <div class="reward-admin-board__header">
        <div>
          <h2 class="card-title">Badge table</h2>
        </div>
      </div>

      <div class="card reward-admin-table-card">
        <div class="table-wrap reward-admin-table-wrap">
          <table class="reward-admin-table badge-admin-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Badge</th>
                <th>Criteria</th>
                <th>Created by</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($badges as $badge): ?>
                <?php
                $criteria = trim((string)($badge['criteria'] ?? ''));
                $isAutomatic = $criteria !== '';
                ?>
                <tr id="badge-<?= (int)$badge['badge_id'] ?>" class="reward-admin-table__row badge-admin-table__row<?= $editingBadgeId === (int)$badge['badge_id'] ? ' reward-admin-table__row--editing' : '' ?>">
                  <td class="reward-admin-table__cell reward-admin-table__cell--id" data-label="ID">#<?= (int)$badge['badge_id'] ?></td>
                  <td class="reward-admin-table__reward reward-admin-table__cell reward-admin-table__cell--reward" data-label="Badge">
                    <strong><?= sanitise($badge['name']) ?></strong>
                    <span><?= sanitise($badge['description'] ?: 'No description yet.') ?></span>
                    <span class="meta-copy"><?= (int)$badge['holder_count'] ?> holder<?= (int)$badge['holder_count'] === 1 ? '' : 's' ?></span>
                  </td>
                  <td class="reward-admin-table__cell badge-admin-table__cell badge-admin-table__cell--criteria" data-label="Criteria"><?= sanitise($criteria !== '' ? $criteria : 'Awarded by hand') ?></td>
                  <td class="reward-admin-table__cell badge-admin-table__cell badge-admin-table__cell--created" data-label="Created by"><?= sanitise($badge['created_by_name'] ?? 'System') ?></td>
                  <td class="reward-admin-table__cell reward-admin-table__cell--status" data-label="Status">
                    <span class="badge <?= $isAutomatic ? 'badge-green' : 'badge-grey' ?>">
                      <?= $isAutomatic ? 'auto rule' : 'manual' ?>
                    </span>
                  </td>
                  <td class="reward-admin-table__cell reward-admin-table__cell--actions" data-label="Actions">
                    <div class="reward-admin-table__actions">
                      <a href="<?= sanitise($buildBadgesManagementUrl(['edit_badge' => (int)$badge['badge_id']])) ?>#badge-<?= (int)$badge['badge_id'] ?>" class="btn btn-outline btn-sm">
                        Edit
                      </a>
                      <form method="POST">
                        <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="badge_id" value="<?= (int)$badge['badge_id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this badge?">Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php if ($editingBadgeId === (int)$badge['badge_id']): ?>
                  <tr class="reward-admin-edit-row">
                    <td class="reward-admin-edit-row__cell" colspan="6">
                      <section class="reward-admin-edit-panel">
                        <div class="reward-admin-edit-row__header">
                          <div>
                            <h3 class="card-title">Editing: <?= sanitise($badge['name']) ?></h3>
                            <p class="admin-card-copy">Update this badge here, then continue managing the badge library from the same table.</p>
                          </div>
                          <a href="badges_management.php#badge-<?= (int)$badge['badge_id'] ?>" class="btn btn-outline btn-sm">Close editor</a>
                        </div>

                        <form method="POST" class="reward-admin-form reward-admin-form--inline">
                          <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
                          <input type="hidden" name="action" value="update">
                          <input type="hidden" name="badge_id" value="<?= (int)$badge['badge_id'] ?>">

                          <div class="reward-admin-form-grid reward-admin-form-grid--table badge-admin-form-grid">
                            <div class="form-group reward-admin-form-group reward-admin-form-group--name">
                              <label for="edit_badge_name_<?= (int)$badge['badge_id'] ?>">Badge name</label>
                              <input type="text" id="edit_badge_name_<?= (int)$badge['badge_id'] ?>" name="name" maxlength="<?= BADGE_NAME_MAX ?>" value="<?= sanitise($badge['name']) ?>" required>
                            </div>

                            <div class="form-group reward-admin-form-group">
                              <label for="edit_badge_criteria_<?= (int)$badge['badge_id'] ?>">Criteria</label>
                              <input type="text" id="edit_badge_criteria_<?= (int)$badge['badge_id'] ?>" name="criteria" value="<?= sanitise($badge['criteria'] ?? '') ?>">
                            </div>

                            <div class="form-group reward-admin-form-group reward-admin-form-group--description">
                              <label for="edit_badge_description_<?= (int)$badge['badge_id'] ?>">Description</label>
                              <textarea id="edit_badge_description_<?= (int)$badge['badge_id'] ?>" name="description" rows="3"><?= sanitise($badge['description'] ?? '') ?></textarea>
                            </div>
                          </div>

                          <div class="reward-admin-form-actions">
                            <p class="badge-admin-form-note">Examples: <code>points&gt;=100</code>, <code>streak&gt;=7</code>, <code>logs&gt;=1</code>, <code>goal_achieved</code>.</p>
                            <button type="submit" class="btn btn-primary">Save changes</button>
                          </div>
                        </form>
                      </section>
                    </td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
