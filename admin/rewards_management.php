<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$pdo = getPDO();
$rewardCategories = ['Lifestyle', 'Campus', 'Eco Essentials'];
$rewardForm = [
    'name' => '',
    'description' => '',
    'category' => 'Lifestyle',
    'point_cost' => '50',
    'stock' => '0',
    'image' => '',
    'active' => '1',
];
$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');
    $action = $_POST['action'] ?? 'update';

    if ($action === 'create') {
        $rewardForm = [
            'name' => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'category' => $_POST['category'] ?? 'Lifestyle',
            'point_cost' => (string)((int)($_POST['point_cost'] ?? 50)),
            'stock' => (string)((int)($_POST['stock'] ?? 0)),
            'image' => trim($_POST['image'] ?? ''),
            'active' => isset($_POST['active']) ? '1' : '0',
        ];

        $name = $rewardForm['name'];
        $description = $rewardForm['description'];
        $category = $rewardForm['category'];
        $pointCost = (int)$rewardForm['point_cost'];
        $stock = (int)$rewardForm['stock'];
        $image = $rewardForm['image'];
        $active = $rewardForm['active'] === '1' ? 1 : 0;

        if (strlen($name) < 3) {
            $err = 'Reward name must be at least 3 characters.';
        } elseif (!in_array($category, $rewardCategories, true)) {
            $err = 'Please choose a valid reward category.';
        } elseif ($pointCost < 1) {
            $err = 'Point cost must be at least 1.';
        } elseif ($stock < 0) {
            $err = 'Stock cannot be negative.';
        } else {
            $pdo->prepare(
                'INSERT INTO rewards (name, description, image, category, point_cost, stock, active)
                 VALUES (?, ?, NULLIF(?, ""), ?, ?, ?, ?)'
            )->execute([$name, $description, $image, $category, $pointCost, $stock, $active]);

            $ok = 'Reward added to the catalogue.';
            $rewardForm = [
                'name' => '',
                'description' => '',
                'category' => 'Lifestyle',
                'point_cost' => '50',
                'stock' => '0',
                'image' => '',
                'active' => '1',
            ];
        }
    } elseif ($action === 'update') {
        $rewardId = (int)($_POST['reward_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = $_POST['category'] ?? 'Lifestyle';
        $pointCost = (int)($_POST['point_cost'] ?? 50);
        $stock = (int)($_POST['stock'] ?? 0);
        $image = trim($_POST['image'] ?? '');
        $active = isset($_POST['active']) ? 1 : 0;

        if ($rewardId <= 0) {
            $err = 'Invalid reward selected.';
        } elseif (strlen($name) < 3) {
            $err = 'Reward name must be at least 3 characters.';
        } elseif (!in_array($category, $rewardCategories, true)) {
            $err = 'Please choose a valid reward category.';
        } elseif ($pointCost < 1) {
            $err = 'Point cost must be at least 1.';
        } elseif ($stock < 0) {
            $err = 'Stock cannot be negative.';
        } else {
            $pdo->prepare(
                'UPDATE rewards
                 SET name = ?, description = ?, image = NULLIF(?, ""), category = ?, point_cost = ?, stock = ?, active = ?
                 WHERE reward_id = ?'
            )->execute([$name, $description, $image, $category, $pointCost, $stock, $active, $rewardId]);

            $ok = 'Reward updated successfully.';
        }
    } elseif ($action === 'delete') {
        $rewardId = (int)($_POST['reward_id'] ?? 0);
        if ($rewardId > 0) {
            $pdo->prepare('DELETE FROM rewards WHERE reward_id = ?')->execute([$rewardId]);
            $ok = 'Reward removed from the catalogue.';
        } else {
            $err = 'Invalid reward selected.';
        }
    }
}

$list = $pdo->query('SELECT * FROM rewards ORDER BY active DESC, point_cost ASC, reward_id ASC')->fetchAll() ?: [];
$rewardCount = count($list);
$activeCount = 0;
$hiddenCount = 0;
$outOfStockCount = 0;
$lowStockCount = 0;

foreach ($list as $reward) {
    $isActive = !empty($reward['active']);
    $stockCount = (int)$reward['stock'];

    if ($isActive) {
        $activeCount++;
    } else {
        $hiddenCount++;
    }

    if ($stockCount === 0) {
        $outOfStockCount++;
    } elseif ($stockCount <= 10) {
        $lowStockCount++;
    }
}

$editingRewardId = max(0, (int)($_GET['edit_reward'] ?? 0));
$editingReward = null;
foreach ($list as $reward) {
    if ((int)$reward['reward_id'] === $editingRewardId) {
        $editingReward = $reward;
        break;
    }
}

$buildRewardsManagementUrl = static function (array $overrides = []) use ($editingRewardId): string {
    $params = [
        'edit_reward' => $editingRewardId,
    ];

    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }

    if (($params['edit_reward'] ?? 0) <= 0) {
        unset($params['edit_reward']);
    }

    $query = http_build_query($params);
    return 'rewards_management.php' . ($query ? '?' . $query : '');
};

$pageTitle = 'Rewards';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-shell reward-admin-shell">
  <div class="section-header reward-admin-header">
    <div>
      <h1 class="section-header__title">Rewards management</h1>
      <p class="section-header__text">Manage the Green Shop catalogue with a cleaner admin layout that is easier to read, wireframe, and maintain at normal browser zoom.</p>
    </div>
    <span class="badge badge-blue"><?= $rewardCount ?> reward<?= $rewardCount === 1 ? '' : 's' ?></span>
  </div>

  <section class="reward-admin-summary-grid">
    <article class="card reward-summary-card">
      <span class="reward-summary-card__label">Total rewards</span>
      <strong class="reward-summary-card__value"><?= $rewardCount ?></strong>
    </article>
    <article class="card reward-summary-card">
      <span class="reward-summary-card__label">Visible in shop</span>
      <strong class="reward-summary-card__value"><?= $activeCount ?></strong>
    </article>
    <article class="card reward-summary-card">
      <span class="reward-summary-card__label">Low stock</span>
      <strong class="reward-summary-card__value"><?= $lowStockCount ?></strong>
    </article>
    <article class="card reward-summary-card">
      <span class="reward-summary-card__label">Sold out</span>
      <strong class="reward-summary-card__value"><?= $outOfStockCount ?></strong>
    </article>
  </section>

  <?php if ($err): ?><div class="flash-message flash-error" role="alert"><?= sanitise($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="flash-message flash-success" role="status"><?= sanitise($ok) ?></div><?php endif; ?>

  <section class="card reward-admin-studio">
      <div class="reward-admin-studio-layout">
      <div class="reward-admin-panel__intro reward-admin-panel__intro--compact">
        <span class="badge badge-blue">New reward</span>
        <h2 class="card-title">Create a catalogue item</h2>
        <p class="reward-admin-panel__text">Add a new reward quickly, then manage the catalogue table below for edits, stock updates, and visibility changes.</p>
        <div class="reward-admin-panel__meta">
          <span class="inline-pill-note"><?= $activeCount ?> live</span>
          <span class="inline-pill-note"><?= $hiddenCount ?> hidden</span>
        </div>
      </div>

      <form method="POST" class="reward-admin-form">
        <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
        <input type="hidden" name="action" value="create">

        <div class="reward-admin-form-grid">
          <div class="form-group reward-admin-form-group reward-admin-form-group--name">
            <label for="create_name">Reward name</label>
            <input type="text" id="create_name" name="name" maxlength="150" required value="<?= sanitise($rewardForm['name']) ?>" placeholder="Reusable bottle">
          </div>

          <div class="form-group reward-admin-form-group">
            <label for="create_category">Category</label>
            <select id="create_category" name="category">
              <?php foreach ($rewardCategories as $category): ?>
                <option value="<?= sanitise($category) ?>" <?= $rewardForm['category'] === $category ? 'selected' : '' ?>><?= sanitise($category) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group reward-admin-form-group">
            <label for="create_point_cost">Point cost</label>
            <input type="number" id="create_point_cost" name="point_cost" min="1" value="<?= sanitise($rewardForm['point_cost']) ?>">
          </div>

          <div class="form-group reward-admin-form-group">
            <label for="create_stock">Stock</label>
            <input type="number" id="create_stock" name="stock" min="0" value="<?= sanitise($rewardForm['stock']) ?>">
          </div>

          <div class="form-group reward-admin-form-group reward-admin-form-group--description">
            <label for="create_description">Description</label>
            <textarea id="create_description" name="description" rows="3" placeholder="Short benefit-focused copy for the reward card."><?= sanitise($rewardForm['description']) ?></textarea>
          </div>

          <div class="form-group reward-admin-form-group">
            <label for="create_image">Image URL or path</label>
            <input type="text" id="create_image" name="image" value="<?= sanitise($rewardForm['image']) ?>" placeholder="Optional">
          </div>
        </div>

        <div class="reward-admin-form-actions">
          <label class="reward-admin-toggle">
            <input type="checkbox" name="active" value="1" <?= $rewardForm['active'] === '1' ? 'checked' : '' ?>>
            Show immediately in the participant shop
          </label>
          <button type="submit" class="btn btn-primary">Add reward</button>
        </div>
      </form>
    </div>
  </section>

  <section class="reward-admin-board">
    <div class="reward-admin-board__header">
      <div>
        <h2 class="card-title">Catalogue table</h2>
      </div>
    </div>

    <?php if (empty($list)): ?>
      <div class="card empty-state">
        <h2 class="card-title empty-state__title">No rewards yet</h2>
        <p class="empty-state__text">Add your first reward from the studio panel to start building the Green Shop catalogue.</p>
      </div>
    <?php else: ?>
      <div class="card reward-admin-table-card">
        <div class="table-wrap reward-admin-table-wrap">
          <table class="reward-admin-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Reward</th>
                <th>Category</th>
                <th>Cost</th>
                <th>Stock</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($list as $reward): ?>
                <?php
                $stockCount = (int)$reward['stock'];
                $isActive = !empty($reward['active']);
                $stockTone = $stockCount === 0 ? 'danger' : ($stockCount <= 10 ? 'warning' : 'ok');
                $stockLabel = $stockCount === 0 ? 'Sold out' : ($stockCount <= 10 ? $stockCount . ' low' : $stockCount . ' in stock');
                ?>
                <tr id="reward-<?= (int)$reward['reward_id'] ?>" class="reward-admin-table__row<?= $editingRewardId === (int)$reward['reward_id'] ? ' reward-admin-table__row--editing' : '' ?>">
                  <td class="reward-admin-table__cell reward-admin-table__cell--id" data-label="ID">#<?= (int)$reward['reward_id'] ?></td>
                  <td class="reward-admin-table__reward reward-admin-table__cell reward-admin-table__cell--reward" data-label="Reward">
                    <strong><?= sanitise($reward['name']) ?></strong>
                    <span><?= sanitise($reward['description'] ?: 'No description yet.') ?></span>
                  </td>
                  <td class="reward-admin-table__cell reward-admin-table__cell--category" data-label="Category"><?= sanitise($reward['category']) ?></td>
                  <td class="reward-admin-table__cell reward-admin-table__cell--cost" data-label="Cost"><?= (int)$reward['point_cost'] ?> pts</td>
                  <td class="reward-admin-table__cell reward-admin-table__cell--stock" data-label="Stock">
                    <span class="reward-admin-stock reward-admin-stock--<?= sanitise($stockTone) ?>">
                      <?= sanitise($stockLabel) ?>
                    </span>
                  </td>
                  <td class="reward-admin-table__cell reward-admin-table__cell--status" data-label="Status">
                    <span class="badge <?= $isActive ? 'badge-green' : 'badge-grey' ?>">
                      <?= $isActive ? 'active' : 'draft' ?>
                    </span>
                  </td>
                  <td class="reward-admin-table__cell reward-admin-table__cell--actions" data-label="Actions">
                    <div class="reward-admin-table__actions">
                      <a href="<?= sanitise($buildRewardsManagementUrl(['edit_reward' => (int)$reward['reward_id']])) ?>#reward-<?= (int)$reward['reward_id'] ?>" class="btn btn-outline btn-sm">
                        Edit
                      </a>
                      <form method="POST">
                        <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="reward_id" value="<?= (int)$reward['reward_id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this reward?');">Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php if ($editingRewardId === (int)$reward['reward_id']): ?>
                  <tr class="reward-admin-edit-row">
                    <td class="reward-admin-edit-row__cell" colspan="7">
                      <section class="reward-admin-edit-panel">
                        <div class="reward-admin-edit-row__header">
                          <div>
                            <h3 class="card-title">Editing: <?= sanitise($reward['name']) ?></h3>
                            <p class="admin-card-copy">Update this reward here, then continue managing the catalogue from the same list.</p>
                          </div>
                          <a href="rewards_management.php#reward-<?= (int)$reward['reward_id'] ?>" class="btn btn-outline btn-sm">Close editor</a>
                        </div>

                        <form method="POST" class="reward-admin-form reward-admin-form--inline">
                          <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
                          <input type="hidden" name="action" value="update">
                          <input type="hidden" name="reward_id" value="<?= (int)$reward['reward_id'] ?>">

                          <div class="reward-admin-form-grid reward-admin-form-grid--table">
                            <div class="form-group reward-admin-form-group reward-admin-form-group--name">
                              <label for="edit_name_<?= (int)$reward['reward_id'] ?>">Reward name</label>
                              <input type="text" id="edit_name_<?= (int)$reward['reward_id'] ?>" name="name" maxlength="150" value="<?= sanitise($reward['name']) ?>" required>
                            </div>

                            <div class="form-group reward-admin-form-group">
                              <label for="edit_category_<?= (int)$reward['reward_id'] ?>">Category</label>
                              <select id="edit_category_<?= (int)$reward['reward_id'] ?>" name="category">
                                <?php foreach ($rewardCategories as $category): ?>
                                  <option value="<?= sanitise($category) ?>" <?= $reward['category'] === $category ? 'selected' : '' ?>><?= sanitise($category) ?></option>
                                <?php endforeach; ?>
                              </select>
                            </div>

                            <div class="form-group reward-admin-form-group">
                              <label for="edit_point_cost_<?= (int)$reward['reward_id'] ?>">Point cost</label>
                              <input type="number" id="edit_point_cost_<?= (int)$reward['reward_id'] ?>" name="point_cost" min="1" value="<?= (int)$reward['point_cost'] ?>">
                            </div>

                            <div class="form-group reward-admin-form-group">
                              <label for="edit_stock_<?= (int)$reward['reward_id'] ?>">Stock</label>
                              <input type="number" id="edit_stock_<?= (int)$reward['reward_id'] ?>" name="stock" min="0" value="<?= (int)$reward['stock'] ?>">
                            </div>

                            <div class="form-group reward-admin-form-group reward-admin-form-group--description">
                              <label for="edit_description_<?= (int)$reward['reward_id'] ?>">Description</label>
                              <textarea id="edit_description_<?= (int)$reward['reward_id'] ?>" name="description" rows="3"><?= sanitise($reward['description'] ?? '') ?></textarea>
                            </div>

                            <div class="form-group reward-admin-form-group">
                              <label for="edit_image_<?= (int)$reward['reward_id'] ?>">Image URL or path</label>
                              <input type="text" id="edit_image_<?= (int)$reward['reward_id'] ?>" name="image" value="<?= sanitise($reward['image'] ?? '') ?>" placeholder="Optional">
                            </div>
                          </div>

                          <div class="reward-admin-form-actions">
                            <label class="reward-admin-toggle">
                              <input type="checkbox" name="active" value="1" <?= !empty($reward['active']) ? 'checked' : '' ?>>
                              Visible in participant shop
                            </label>
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
