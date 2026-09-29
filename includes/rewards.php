<?php
/**
 * EcoTrack — Green Shop: redeeming rewards and fulfilling redemptions.
 *
 * A redemption starts "pending" (points taken, item owed), then an admin
 * marks it "fulfilled" when the item is handed over, or "cancelled", which
 * refunds the points and puts the item back in stock.
 */

/**
 * Spend points on a reward.
 *
 * @return array{ok: bool, message: string}
 */
function redeemReward(int $userId, int $rewardId): array
{
    if ($rewardId <= 0) {
        return ['ok' => false, 'message' => 'Invalid reward.'];
    }

    return inTransaction(function (PDO $pdo) use ($userId, $rewardId): array {
        // User first, then reward: every path that locks both uses this order.
        $user = lockUser($userId);

        $stmt = $pdo->prepare('SELECT * FROM rewards WHERE reward_id = ? AND active = 1 FOR UPDATE');
        $stmt->execute([$rewardId]);
        $reward = $stmt->fetch();

        if ($user === null) {
            return ['ok' => false, 'message' => 'User account not found.'];
        }
        if (!$reward) {
            return ['ok' => false, 'message' => 'Reward not available.'];
        }
        if ((int)$reward['stock'] < 1) {
            return ['ok' => false, 'message' => 'Out of stock.'];
        }

        $cost = (int)$reward['point_cost'];
        if ((int)$user['points'] < $cost) {
            return ['ok' => false, 'message' => 'Not enough points.'];
        }

        $pdo->prepare('UPDATE rewards SET stock = stock - 1 WHERE reward_id = ?')->execute([$rewardId]);
        $pdo->prepare('INSERT INTO redemptions (user_id, reward_id, points_spent) VALUES (?, ?, ?)')
            ->execute([$userId, $rewardId, $cost]);
        $redemptionId = (int)$pdo->lastInsertId();

        // The balance was checked under the same lock, so this cannot come
        // up short. If it ever does, throwing rolls the whole redemption back.
        if (!awardPoints($userId, -$cost, 'redemption', 'Redeemed: ' . $reward['name'], $redemptionId)) {
            throw new RuntimeException('Points deduction failed after the balance check.');
        }

        return ['ok' => true, 'message' => 'Redeemed: ' . $reward['name'] . '. An admin will hand it over soon.'];
    });
}

/**
 * Mark a pending redemption as handed over.
 *
 * @return array{ok: bool, message: string}
 */
function fulfilRedemption(int $adminId, int $redemptionId): array
{
    $stmt = getPDO()->prepare(
        'UPDATE redemptions
         SET status = "fulfilled", handled_by = ?, handled_at = NOW()
         WHERE redemption_id = ? AND status = "pending"'
    );
    $stmt->execute([$adminId, $redemptionId]);

    return $stmt->rowCount() === 1
        ? ['ok' => true, 'message' => 'Redemption marked as fulfilled.']
        : ['ok' => false, 'message' => 'That redemption is no longer pending.'];
}

/**
 * Cancel a pending redemption: refund the points and restock the item.
 *
 * @return array{ok: bool, message: string}
 */
function cancelRedemption(int $adminId, int $redemptionId): array
{
    $pdo = getPDO();

    // Find the owner without a lock, so the locks below can follow the same
    // user-then-rest order that redeemReward() uses.
    $owner = $pdo->prepare('SELECT user_id FROM redemptions WHERE redemption_id = ?');
    $owner->execute([$redemptionId]);
    $userId = (int)$owner->fetchColumn();

    if ($userId === 0) {
        return ['ok' => false, 'message' => 'Redemption not found.'];
    }

    return inTransaction(function (PDO $pdo) use ($adminId, $redemptionId, $userId): array {
        lockUser($userId);

        $stmt = $pdo->prepare(
            'SELECT d.*, r.name
             FROM redemptions d
             JOIN rewards r ON r.reward_id = d.reward_id
             WHERE d.redemption_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$redemptionId]);
        $redemption = $stmt->fetch();

        if (!$redemption || $redemption['status'] !== 'pending') {
            return ['ok' => false, 'message' => 'That redemption is no longer pending.'];
        }

        $pdo->prepare(
            'UPDATE redemptions SET status = "cancelled", handled_by = ?, handled_at = NOW() WHERE redemption_id = ?'
        )->execute([$adminId, $redemptionId]);
        $pdo->prepare('UPDATE rewards SET stock = stock + 1 WHERE reward_id = ?')
            ->execute([(int)$redemption['reward_id']]);

        awardPoints(
            (int)$redemption['user_id'],
            (int)$redemption['points_spent'],
            'refund',
            'Refund: ' . $redemption['name'],
            $redemptionId
        );

        return ['ok' => true, 'message' => 'Redemption cancelled. ' . (int)$redemption['points_spent'] . ' points refunded.'];
    });
}

/**
 * Remove a reward from the catalogue. One that has ever been redeemed is
 * hidden instead of deleted, so nobody's redemption history disappears.
 *
 * @return array{ok: bool, message: string}
 */
function removeReward(int $rewardId): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM redemptions WHERE reward_id = ?');
    $stmt->execute([$rewardId]);
    $redeemed = (int)$stmt->fetchColumn();

    if ($redeemed > 0) {
        $pdo->prepare('UPDATE rewards SET active = 0 WHERE reward_id = ?')->execute([$rewardId]);
        return ['ok' => true, 'message' => sprintf(
            'This reward has %d redemption%s on record, so it was hidden from the shop instead of deleted.',
            $redeemed,
            $redeemed === 1 ? '' : 's'
        )];
    }

    $stmt = $pdo->prepare('DELETE FROM rewards WHERE reward_id = ?');
    $stmt->execute([$rewardId]);

    return $stmt->rowCount() > 0
        ? ['ok' => true, 'message' => 'Reward removed from the catalogue.']
        : ['ok' => false, 'message' => 'Reward not found.'];
}

/**
 * Check a reward form. Returns the cleaned values, or an error message.
 *
 * @return array{ok: bool, message?: string, values?: array}
 */
function validateRewardInput(array $input): array
{
    $values = [
        'name'        => trim((string)($input['name'] ?? '')),
        'description' => trim((string)($input['description'] ?? '')),
        'category'    => (string)($input['category'] ?? 'Lifestyle'),
        'point_cost'  => (int)($input['point_cost'] ?? 50),
        'stock'       => (int)($input['stock'] ?? 0),
        'active'      => isset($input['active']) ? 1 : 0,
    ];

    $error = null;
    if (mb_strlen($values['name']) < 3 || isTooLong($values['name'], REWARD_NAME_MAX)) {
        $error = 'Reward name must be 3-' . REWARD_NAME_MAX . ' characters.';
    } elseif (isTooLong($values['description'], BODY_MAX)) {
        $error = 'Description must be ' . BODY_MAX . ' characters or fewer.';
    } elseif (!in_array($values['category'], REWARD_CATEGORIES, true)) {
        $error = 'Please choose a valid reward category.';
    } elseif ($values['point_cost'] < 1 || $values['point_cost'] > REWARD_COST_MAX) {
        $error = 'Point cost must be between 1 and ' . number_format(REWARD_COST_MAX) . '.';
    } elseif ($values['stock'] < 0 || $values['stock'] > REWARD_STOCK_MAX) {
        $error = 'Stock must be between 0 and ' . number_format(REWARD_STOCK_MAX) . '.';
    }

    return $error === null ? ['ok' => true, 'values' => $values] : ['ok' => false, 'message' => $error];
}

/**
 * A participant's most recent redemptions, with their status.
 */
function getUserRedemptions(int $userId, int $limit = 10): array
{
    $stmt = getPDO()->prepare(
        'SELECT d.points_spent, d.redeemed_at, d.status, r.name
         FROM redemptions d
         JOIN rewards r ON r.reward_id = d.reward_id
         WHERE d.user_id = ?
         ORDER BY d.redeemed_at DESC, d.redemption_id DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Redemptions waiting to be handed over, oldest first.
 */
function getPendingRedemptions(int $limit = 50): array
{
    $stmt = getPDO()->prepare(
        'SELECT d.redemption_id, d.points_spent, d.redeemed_at, r.name AS reward_name, u.username, u.email
         FROM redemptions d
         JOIN rewards r ON r.reward_id = d.reward_id
         JOIN users u ON u.user_id = d.user_id
         WHERE d.status = "pending"
         ORDER BY d.redeemed_at ASC, d.redemption_id ASC
         LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
