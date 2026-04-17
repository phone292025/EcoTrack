<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$pdo = getPDO();
$badgeForm = [
    'name' => '',
    'description' => '',
    'criteria' => '',
    'icon' => '',
];
$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create') {
        $badgeForm = [
            'name' => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'criteria' => trim($_POST['criteria'] ?? ''),
            'icon' => trim($_POST['icon'] ?? ''),
        ];

        if (strlen($badgeForm['name']) < 2) {
            $err = 'Badge name must be at least 2 characters.';
        } else {
            $pdo->prepare(
                'INSERT INTO badges (name, description, icon, criteria, created_by)
                 VALUES (?, ?, NULLIF(?, ""), NULLIF(?, ""), ?)'
            )->execute([
                $badgeForm['name'],
                $badgeForm['description'],
                $badgeForm['icon'],
                $badgeForm['criteria'],
                currentUserId(),
            ]);

            $ok = 'Badge created.';
            $badgeForm = [
                'name' => '',
                'description' => '',
                'criteria' => '',
                'icon' => '',
            ];
        }
    } elseif ($action === 'update') {
        $badgeId = (int)($_POST['badge_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $criteria = trim($_POST['criteria'] ?? '');
        $icon = trim($_POST['icon'] ?? '');

        if ($badgeId <= 0) {
            $err = 'Invalid badge selected.';
        } elseif (strlen($name) < 2) {
            $err = 'Badge name must be at least 2 characters.';
        } else {
            $pdo->prepare(
                'UPDATE badges
                 SET name = ?, description = ?, icon = NULLIF(?, ""), criteria = NULLIF(?, "")
                 WHERE badge_id = ?'
            )->execute([$name, $description, $icon, $criteria, $badgeId]);
            $ok = 'Badge updated.';
        }
    } elseif ($action === 'delete') {
        $badgeId = (int)($_POST['badge_id'] ?? 0);
        if ($badgeId > 0) {
            $pdo->prepare('DELETE FROM badges WHERE badge_id = ?')->execute([$badgeId]);
            $ok = 'Badge deleted.';
        } else {
            $err = 'Invalid badge selected.';
        }
    }
}

$badges = $pdo->query(
    'SELECT b.*, u.username AS created_by_name
     FROM badges b
     LEFT JOIN users u ON u.user_id = b.created_by
     ORDER BY b.badge_id ASC'
)->fetchAll() ?: [];

$badgeCount = count($badges);
$ruleCount = 0;
$iconCount = 0;
$manualCount = 0;

foreach ($badges as $badge) {
    $hasCriteria = trim((string)($badge['criteria'] ?? '')) !== '';
    $hasIcon = trim((string)($badge['icon'] ?? '')) !== '';

    if ($hasCriteria) {
        $ruleCount++;
    } else {
        $manualCount++;
    }

    if ($hasIcon) {
        $iconCount++;
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
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-shell reward-admin-shell badge-admin-shell">
  <div class="section-header reward-admin-header">
    <div>
      <h1 class="section-header__title">Badge management</h1>
      <p class="section-header__text">Create and tune achievement badges with milestone criteria that the badge engine can award automatically.</p>
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
    <article class="card reward-summary-card">
      <span class="reward-summary-card__label">With icon</span>
      <strong class="reward-summary-card__value"><?= $iconCount ?></strong>
    </article>
  </section>

  <?php if ($err): ?><div class="flash-message flash-error" role="alert"><?= sanitise($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="flash-message flash-success" role="status"><?= sanitise($ok) ?></div><?php endif; ?>

  <section class="card reward-admin-studio">
    <div class="reward-admin-studio-layout">
      <div class="reward-admin-panel__intro reward-admin-panel__intro--compact">
        <span class="badge badge-blue">New badge</span>
        <h2 class="card-title">Create a badge rule</h2>
        <p class="reward-admin-panel__text">Set the badge name, rule, and optional icon in one place, then manage the full badge library below.</p>
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
            <input type="text" id="create_badge_name" name="name" maxlength="100" value="<?= sanitise($badgeForm['name']) ?>" required placeholder="Eco Champion">
          </div>

          <div class="form-group reward-admin-form-group">
            <label for="create_badge_criteria">Criteria</label>
            <input type="text" id="create_badge_criteria" name="criteria" value="<?= sanitise($badgeForm['criteria']) ?>" placeholder="points>=100">
          </div>

          <div class="form-group reward-admin-form-group">
            <label for="create_badge_icon">Icon path or filename</label>
            <input type="text" id="create_badge_icon" name="icon" value="<?= sanitise($badgeForm['icon']) ?>" placeholder="badge_100pts.svg">
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
                <th>Icon</th>
                <th>Created by</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($badges as $badge): ?>
                <?php
                $criteria = trim((string)($badge['criteria'] ?? ''));
                $icon = trim((string)($badge['icon'] ?? ''));
                $isAutomatic = $criteria !== '';
                ?>
                <tr id="badge-<?= (int)$badge['badge_id'] ?>" class="reward-admin-table__row badge-admin-table__row<?= $editingBadgeId === (int)$badge['badge_id'] ? ' reward-admin-table__row--editing' : '' ?>">
                  <td class="reward-admin-table__cell reward-admin-table__cell--id" data-label="ID">#<?= (int)$badge['badge_id'] ?></td>
                  <td class="reward-admin-table__reward reward-admin-table__cell reward-admin-table__cell--reward" data-label="Badge">
                    <strong><?= sanitise($badge['name']) ?></strong>
                    <span><?= sanitise($badge['description'] ?: 'No description yet.') ?></span>
                  </td>
                  <td class="reward-admin-table__cell badge-admin-table__cell badge-admin-table__cell--criteria" data-label="Criteria"><?= sanitise($criteria !== '' ? $criteria : 'Manual award or custom trigger') ?></td>
                  <td class="reward-admin-table__cell badge-admin-table__cell badge-admin-table__cell--icon" data-label="Icon"><?= sanitise($icon !== '' ? $icon : 'No icon yet') ?></td>
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
                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this badge?');">Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php if ($editingBadgeId === (int)$badge['badge_id']): ?>
                  <tr class="reward-admin-edit-row">
                    <td class="reward-admin-edit-row__cell" colspan="7">
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
                              <input type="text" id="edit_badge_name_<?= (int)$badge['badge_id'] ?>" name="name" maxlength="100" value="<?= sanitise($badge['name']) ?>" required>
                            </div>

                            <div class="form-group reward-admin-form-group">
                              <label for="edit_badge_criteria_<?= (int)$badge['badge_id'] ?>">Criteria</label>
                              <input type="text" id="edit_badge_criteria_<?= (int)$badge['badge_id'] ?>" name="criteria" value="<?= sanitise($badge['criteria'] ?? '') ?>">
                            </div>

                            <div class="form-group reward-admin-form-group">
                              <label for="edit_badge_icon_<?= (int)$badge['badge_id'] ?>">Icon path or filename</label>
                              <input type="text" id="edit_badge_icon_<?= (int)$badge['badge_id'] ?>" name="icon" value="<?= sanitise($badge['icon'] ?? '') ?>">
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
