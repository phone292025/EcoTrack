<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('participant');

$pdo = getPDO();
$uid = currentUserId();
$user = getUserById($uid);
$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');
    $rid = (int)($_POST['reward_id'] ?? 0);

    if ($rid <= 0) {
        $err = 'Invalid reward.';
    } else {
        $pdo->beginTransaction();
        try {
            $userStmt = $pdo->prepare('SELECT user_id, points FROM users WHERE user_id = ? FOR UPDATE');
            $userStmt->execute([$uid]);
            $userRow = $userStmt->fetch();

            $stmt = $pdo->prepare('SELECT * FROM rewards WHERE reward_id = ? AND active = 1 FOR UPDATE');
            $stmt->execute([$rid]);
            $reward = $stmt->fetch();

            if (!$userRow) {
                $pdo->rollBack();
                $err = 'User account not found.';
            } elseif (!$reward) {
                $pdo->rollBack();
                $err = 'Reward not available.';
            } elseif ((int)$reward['stock'] < 1) {
                $pdo->rollBack();
                $err = 'Out of stock.';
            } elseif ((int)$userRow['points'] < (int)$reward['point_cost']) {
                $pdo->rollBack();
                $err = 'Not enough points.';
            } else {
                $cost = (int)$reward['point_cost'];
                $updateStock = $pdo->prepare('UPDATE rewards SET stock = stock - 1 WHERE reward_id = ? AND stock > 0');
                $updateStock->execute([$rid]);

                if ($updateStock->rowCount() !== 1) {
                    $pdo->rollBack();
                    $err = 'Could not complete redemption.';
                } else {
                    $pdo->prepare(
                        'INSERT INTO redemptions (user_id, reward_id, points_spent) VALUES (?, ?, ?)'
                    )->execute([$uid, $rid, $cost]);
                    $redemptionId = (int)$pdo->lastInsertId();
                    awardPoints($uid, -$cost, 'Redeemed: ' . $reward['name'], $redemptionId);
                    $pdo->commit();
                    $ok = 'Redeemed: ' . $reward['name'] . '.';
                    $user = getUserById($uid);
                }
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

$categoryFilter = trim($_GET['category'] ?? '');
$searchQuery = trim($_GET['q'] ?? '');
$allowedCategories = ['Lifestyle', 'Campus', 'Eco Essentials'];

$sql = 'SELECT * FROM rewards WHERE active = 1';
$params = [];

if ($categoryFilter !== '' && in_array($categoryFilter, $allowedCategories, true)) {
    $sql .= ' AND category = ?';
    $params[] = $categoryFilter;
}

if ($searchQuery !== '') {
    $sql .= ' AND (name LIKE ? OR description LIKE ?)';
    $like = '%' . $searchQuery . '%';
    $params[] = $like;
    $params[] = $like;
}

$sql .= ' ORDER BY point_cost ASC, reward_id ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rewards = $stmt->fetchAll() ?: [];

$redemptionStmt = $pdo->prepare(
    'SELECT d.points_spent, d.redeemed_at, r.name
     FROM redemptions d
     INNER JOIN rewards r ON r.reward_id = d.reward_id
     WHERE d.user_id = ?
     ORDER BY d.redeemed_at DESC, d.redemption_id DESC
     LIMIT 10'
);
$redemptionStmt->execute([$uid]);
$redemptions = $redemptionStmt->fetchAll() ?: [];

$pageTitle = 'Green Shop';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-shell shop-page" style="max-width:1140px;">
  <div class="card shop-hero">
    <div class="shop-hero__row">
      <div>
        <h1 class="shop-hero__title">Green Shop</h1>
        <p class="shop-hero__text">Browse eco rewards by category, search for specific items, and redeem your points for useful sustainable rewards.</p>
      </div>
      <div class="shop-hero__balance">
        <div class="shop-hero__balance-label">Your balance</div>
        <div class="shop-hero__balance-value"><?= (int)($user['points'] ?? 0) ?> pts</div>
      </div>
    </div>
  </div>

  <?php if ($err): ?><div class="flash-message flash-error" role="alert"><?= sanitise($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="flash-message flash-success" role="status"><?= sanitise($ok) ?></div><?php endif; ?>

  <div class="card" style="margin-bottom:var(--space-4);">
    <form method="GET" class="shop-filter-form">
      <div class="form-group" style="margin-bottom:0;">
        <label for="shop_q">Search rewards</label>
        <input type="text" id="shop_q" name="q" value="<?= sanitise($searchQuery) ?>" placeholder="Search by name or description">
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label for="shop_category">Category</label>
        <select id="shop_category" name="category">
          <option value="">All categories</option>
          <?php foreach ($allowedCategories as $category): ?>
            <option value="<?= sanitise($category) ?>" <?= $categoryFilter === $category ? 'selected' : '' ?>><?= sanitise($category) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="shop-filter-actions">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="<?= BASE_URL ?>/participant/shop.php" class="btn btn-outline">Reset</a>
      </div>
    </form>
  </div>

  <?php if (empty($rewards)): ?>
    <div class="card empty-state">
      <h2 class="card-title empty-state__title">No rewards match this filter</h2>
      <p class="empty-state__text">Try a different category or search term to find something you can redeem.</p>
    </div>
  <?php else: ?>
    <div class="card" style="margin-bottom:var(--space-4);">
      <div class="card-header-row">
        <div class="card-header-row__content">
          <h2 class="card-title">Available Rewards Table</h2>
        </div>
        <span class="inline-pill-note"><?= count($rewards) ?> rewards</span>
      </div>

      <div class="table-wrap">
        <table class="shop-table">
          <thead>
            <tr>
              <th>Reward</th>
              <th>Category</th>
              <th>Points</th>
              <th>Stock</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rewards as $reward): ?>
              <?php
              $stock = (int)$reward['stock'];
              $cost = (int)$reward['point_cost'];
              $canRedeem = $stock > 0 && (int)($user['points'] ?? 0) >= $cost;
              $statusText = $stock < 1 ? 'Out of stock' : (((int)($user['points'] ?? 0) < $cost) ? 'Need more points' : 'Ready to redeem');
              ?>
              <tr>
                <td class="shop-table__name shop-table__cell--reward" data-label="Reward">
                  <strong><?= sanitise($reward['name']) ?></strong>
                  <div class="shop-table__description"><?= sanitise($reward['description'] ?? 'No description yet.') ?></div>
                </td>
                <td class="shop-table__cell--category" data-label="Category"><?= sanitise($reward['category']) ?></td>
                <td class="shop-table__cell--points" data-label="Points"><?= $cost ?> pts</td>
                <td class="shop-table__cell--stock" data-label="Stock"><?= $stock ?></td>
                <td class="shop-table__cell--status" data-label="Status"><?= sanitise($statusText) ?></td>
                <td class="shop-table__action shop-table__cell--action" data-label="Action">
                  <form method="POST">
                    <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
                    <input type="hidden" name="reward_id" value="<?= (int)$reward['reward_id'] ?>">
                    <button type="submit" class="btn btn-primary" <?= $canRedeem ? '' : 'disabled' ?>>
                      <?= $stock < 1 ? 'Out of stock' : (((int)($user['points'] ?? 0) < $cost) ? 'Need more points' : 'Redeem') ?>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php endif; ?>

  <div class="card" style="margin-top:var(--space-4);">
    <div class="card-header-row">
      <div class="card-header-row__content">
        <h2 class="card-title">My Recent Redemptions</h2>
      </div>
      <span class="inline-pill-note"><?= count($redemptions) ?> entries</span>
    </div>

    <?php if (empty($redemptions)): ?>
      <div class="points-empty-state">
        <p class="card-copy">You have not redeemed any rewards yet.</p>
        <p class="meta-copy">Once you redeem something from the Green Shop, it will appear here automatically.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap points-table-wrap">
        <table class="points-history-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Reward</th>
              <th>Points spent</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($redemptions as $row): ?>
              <tr>
                <td class="shop-history-table__cell--date" data-label="Date"><?= sanitise((string)$row['redeemed_at']) ?></td>
                <td class="shop-history-table__cell--reward" data-label="Reward"><?= sanitise($row['name'] ?: 'Reward') ?></td>
                <td data-label="Points spent" class="points-history-table__delta points-history-table__delta--negative shop-history-table__cell--spent">
                  -<?= (int)$row['points_spent'] ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
