<?php
require_once __DIR__ . '/../includes/bootstrap.php';

requireRole('admin');

$pdo = getPDO();
$rewardCategories = REWARD_CATEGORIES;
$rewardForm = [
    'name' => '',
    'description' => '',
    'category' => 'Lifestyle',
    'point_cost' => '50',
    'stock' => '0',
    'active' => '1',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');
    $action = $_POST['action'] ?? 'update';
    $rewardId = (int)($_POST['reward_id'] ?? 0);
    $redemptionId = (int)($_POST['redemption_id'] ?? 0);

    if ($action === 'create') {
        $check = validateRewardInput($_POST);

        if (!$check['ok']) {
            setFlash('error', $check['message']);
            $posted = array_intersect_key($_POST, $rewardForm);
            $posted['active'] = isset($_POST['active']) ? '1' : '0';
            setFormOld($posted);
        } else {
            $v = $check['values'];
            $pdo->prepare(
                'INSERT INTO rewards (name, description, image, category, point_cost, stock, active)
                 VALUES (?, ?, NULL, ?, ?, ?, ?)'
            )->execute([$v['name'], $v['description'], $v['category'], $v['point_cost'], $v['stock'], $v['active']]);

            setFlash('success', 'Reward added to the catalogue.');
        }
    } elseif ($action === 'update') {
        $check = validateRewardInput($_POST);

        if ($rewardId <= 0) {
            setFlash('error', 'Invalid reward selected.');
        } elseif (!$check['ok']) {
            setFlash('error', $check['message']);
        } else {
            $v = $check['values'];
            $pdo->prepare(
                'UPDATE rewards
                 SET name = ?, description = ?, category = ?, point_cost = ?, stock = ?, active = ?
                 WHERE reward_id = ?'
            )->execute([$v['name'], $v['description'], $v['category'], $v['point_cost'], $v['stock'], $v['active'], $rewardId]);

            setFlash('success', 'Reward updated successfully.');
        }
    } elseif ($action === 'delete') {
        flashResult($rewardId > 0 ? removeReward($rewardId) : ['ok' => false, 'message' => 'Invalid reward selected.']);
    } elseif ($action === 'fulfil_redemption') {
        flashResult(fulfilRedemption(currentUserId(), $redemptionId));
    } elseif ($action === 'cancel_redemption') {
        flashResult(cancelRedemption(currentUserId(), $redemptionId));
    }

    // Redirect so refreshing cannot repeat the submission.
    redirectToSelf($_SERVER['QUERY_STRING'] ?? '');
}

$flash = takeFlash();
$old = takeFormOld();
if ($old) {
    $rewardForm = array_merge($rewardForm, $old);
}
$pendingRedemptions = getPendingRedemptions();

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
require_once __DIR__ . '/../layout/header.php';
?>

<div class="container page-shell reward-admin-shell">
  <div class="section-header reward-admin-header">
    <div>
      <h1 class="section-header__title">Rewards management</h1>
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

  <?php renderFlash($flash); ?>

  <section class="card mb-4" id="redemptions">
    <div class="card-header-row">
      <div class="card-header-row__content">
        <h2 class="card-title">Redemptions to hand over</h2>
      </div>
      <span class="inline-pill-note"><?= count($pendingRedemptions) ?> pending</span>
    </div>

    <?php if (empty($pendingRedemptions)): ?>
      <p class="card-copy">Nothing waiting. New redemptions from the Green Shop appear here.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Redeemed</th>
              <th>Participant</th>
              <th>Reward</th>
              <th>Points</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingRedemptions as $redemption): ?>
              <tr>
                <td><?= sanitise($redemption['redeemed_at']) ?></td>
                <td>
                  <strong><?= sanitise($redemption['username']) ?></strong><br>
                  <span class="meta-copy"><?= sanitise($redemption['email']) ?></span>
                </td>
                <td><?= sanitise($redemption['reward_name']) ?></td>
                <td><?= (int)$redemption['points_spent'] ?></td>
                <td>
                  <div class="inline-actions">
                    <form method="POST">
                      <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
                      <input type="hidden" name="action" value="fulfil_redemption">
                      <input type="hidden" name="redemption_id" value="<?= (int)$redemption['redemption_id'] ?>">
                      <button type="submit" class="btn btn-primary btn-sm">Mark handed over</button>
                    </form>
                    <form method="POST">
                      <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
                      <input type="hidden" name="action" value="cancel_redemption">
                      <input type="hidden" name="redemption_id" value="<?= (int)$redemption['redemption_id'] ?>">
                      <button type="submit" class="btn btn-outline btn-sm"
                              data-confirm="Cancel this redemption and refund <?= (int)$redemption['points_spent'] ?> points?">Cancel &amp; refund</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card reward-admin-studio">
      <div class="reward-admin-studio-layout">
      <div class="reward-admin-panel__intro reward-admin-panel__intro--compact">
        <span class="badge badge-blue">New reward</span>
        <h2 class="card-title">Create a catalogue item</h2>
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
            <input type="text" id="create_name" name="name" maxlength="<?= REWARD_NAME_MAX ?>" required value="<?= sanitise($rewardForm['name']) ?>" placeholder="Reusable bottle">
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
            <input type="number" id="create_point_cost" name="point_cost" min="1" max="<?= REWARD_COST_MAX ?>" value="<?= sanitise($rewardForm['point_cost']) ?>">
          </div>

          <div class="form-group reward-admin-form-group">
            <label for="create_stock">Stock</label>
            <input type="number" id="create_stock" name="stock" min="0" max="<?= REWARD_STOCK_MAX ?>" value="<?= sanitise($rewardForm['stock']) ?>">
          </div>

          <div class="form-group reward-admin-form-group reward-admin-form-group--description">
            <label for="create_description">Description</label>
            <textarea id="create_description" name="description" rows="3" placeholder="Short benefit-focused copy for the reward card."><?= sanitise($rewardForm['description']) ?></textarea>
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
                        <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this reward? If anyone has redeemed it, it will be hidden instead.">Delete</button>
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
                              <input type="text" id="edit_name_<?= (int)$reward['reward_id'] ?>" name="name" maxlength="<?= REWARD_NAME_MAX ?>" value="<?= sanitise($reward['name']) ?>" required>
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
                              <input type="number" id="edit_point_cost_<?= (int)$reward['reward_id'] ?>" name="point_cost" min="1" max="<?= REWARD_COST_MAX ?>" value="<?= (int)$reward['point_cost'] ?>">
                            </div>

                            <div class="form-group reward-admin-form-group">
                              <label for="edit_stock_<?= (int)$reward['reward_id'] ?>">Stock</label>
                              <input type="number" id="edit_stock_<?= (int)$reward['reward_id'] ?>" name="stock" min="0" max="<?= REWARD_STOCK_MAX ?>" value="<?= (int)$reward['stock'] ?>">
                            </div>

                            <div class="form-group reward-admin-form-group reward-admin-form-group--description">
                              <label for="edit_description_<?= (int)$reward['reward_id'] ?>">Description</label>
                              <textarea id="edit_description_<?= (int)$reward['reward_id'] ?>" name="description" rows="3"><?= sanitise($reward['description'] ?? '') ?></textarea>
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

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
