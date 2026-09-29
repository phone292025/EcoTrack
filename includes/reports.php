<?php
/**
 * EcoTrack — Read-only queries behind the dashboards, profile and charts.
 * Nothing in this file writes to the database.
 */

/**
 * Fetch a single user by ID. Returns false if not found.
 */
function getUserById(int $userId): array|false
{
    $stmt = getPDO()->prepare(
        'SELECT user_id, username, email, role, points, streak, last_checkin, avatar, created_at
         FROM users WHERE user_id = ?'
    );
    $stmt->execute([$userId]);

    return $stmt->fetch();
}

/**
 * Top-N participants by points. Equal scores share a rank and the next
 * distinct score skips ahead (1, 2, 2, 4).
 *
 * @return array [{rank, user_id, username, points, streak, badge_count}]
 */
function getLeaderboard(int $limit = 20): array
{
    $stmt = getPDO()->prepare(
        'SELECT u.user_id, u.username, u.points, u.streak,
                (SELECT COUNT(*) FROM user_badges ub WHERE ub.user_id = u.user_id) AS badge_count
         FROM users u
         WHERE u.role = "participant"
         ORDER BY u.points DESC, u.username ASC
         LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $rank = 0;
    $previousPoints = null;

    foreach ($rows as $position => &$row) {
        $points = (int)$row['points'];
        if ($points !== $previousPoints) {
            $rank = $position + 1;
            $previousPoints = $points;
        }
        $row['rank'] = $rank;
    }
    unset($row);

    return $rows;
}

/**
 * Points earned per category from approved logs (for the donut chart).
 *
 * @return array{labels: string[], data: int[], colors: string[]}
 */
function getCategoryBreakdown(int $userId): array
{
    $stmt = getPDO()->prepare(
        'SELECT c.name, COALESCE(SUM(al.points), 0) AS total
         FROM categories c
         LEFT JOIN activity_logs al
               ON al.cat_id = c.cat_id
              AND al.user_id = ?
              AND al.status  = "approved"
         GROUP BY c.cat_id, c.name
         ORDER BY c.cat_id'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    return [
        'labels' => array_column($rows, 'name'),
        'data'   => array_map('intval', array_column($rows, 'total')),
        'colors' => chartColors(count($rows)),
    ];
}

/* =============================================================
 *  CO2 SAVINGS
 *
 *  Every CO2 figure in the application comes from this one weighting:
 *  a log's points multiplied by its category's co2_per_point rate.
 * ============================================================*/

/**
 * Total CO2 saved by a user, in kg. The single source for this number.
 */
function getUserCo2Kg(int $userId): float
{
    $stmt = getPDO()->prepare(
        'SELECT COALESCE(SUM(al.points * c.co2_per_point), 0)
         FROM activity_logs al
         JOIN categories c ON c.cat_id = al.cat_id
         WHERE al.user_id = ? AND al.status = "approved"'
    );
    $stmt->execute([$userId]);

    return (float)$stmt->fetchColumn();
}

/**
 * Cumulative CO2 savings over time for the line chart.
 *
 * @return array{labels: string[], data: float[]}
 */
function getCO2Savings(int $userId): array
{
    $stmt = getPDO()->prepare(
        'SELECT DATE(al.created_at) AS log_date,
                SUM(al.points * c.co2_per_point) AS co2_day
         FROM activity_logs al
         JOIN categories c ON c.cat_id = al.cat_id
         WHERE al.user_id = ? AND al.status = "approved"
         GROUP BY DATE(al.created_at)
         ORDER BY log_date ASC'
    );
    $stmt->execute([$userId]);

    $labels     = [];
    $data       = [];
    $cumulative = 0.0;

    foreach ($stmt->fetchAll() as $row) {
        $labels[]    = $row['log_date'];
        $cumulative += (float)$row['co2_day'];
        $data[]      = round($cumulative, 3);
    }

    return ['labels' => $labels, 'data' => $data];
}

/**
 * Human-readable impact stats. CO2 uses the same category-weighted figure as
 * the chart, so the tile and the graph beside it always agree.
 *
 * @return array{co2_kg: float, plastic_bottles: float, trees_equivalent: float}
 */
function getEcoImpactSummary(int $userId): array
{
    $stmt = getPDO()->prepare(
        'SELECT COALESCE(SUM(points), 0) FROM activity_logs WHERE user_id = ? AND status = "approved"'
    );
    $stmt->execute([$userId]);
    $points = (int)$stmt->fetchColumn();

    $co2 = getUserCo2Kg($userId);

    return [
        'co2_kg'           => round($co2, 2),
        'plastic_bottles'  => round($points * 0.05, 1),   // 1 pt ≈ 0.05 bottles avoided
        'trees_equivalent' => round($co2 / 21, 3),        // ~21 kg CO2 per tree-year
    ];
}

/* =============================================================
 *  POINTS HISTORY
 * ============================================================*/

/**
 * The most recent entries in a user's points ledger.
 */
function getPointsHistory(int $userId, int $limit = 50): array
{
    $stmt = getPDO()->prepare(
        'SELECT delta, kind, reason, created_at
         FROM points_transactions
         WHERE user_id = ?
         ORDER BY created_at DESC, txn_id DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Lifetime earned and spent totals across the whole ledger. A refund reduces
 * "spent" rather than counting as something earned.
 *
 * @return array{earned: int, spent: int}
 */
function getPointsTotals(int $userId): array
{
    $stmt = getPDO()->prepare(
        'SELECT COALESCE(SUM(CASE WHEN kind NOT IN ("redemption", "refund") AND delta > 0 THEN delta ELSE 0 END), 0) AS earned,
                COALESCE(-SUM(CASE WHEN kind IN ("redemption", "refund") THEN delta ELSE 0 END), 0) AS spent
         FROM points_transactions
         WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];

    return [
        'earned' => (int)($row['earned'] ?? 0),
        'spent'  => (int)($row['spent']  ?? 0),
    ];
}

/* =============================================================
 *  PLATFORM CONTENT
 * ============================================================*/

/**
 * Latest announcements for participant-facing widgets.
 */
function getRecentAnnouncements(int $limit = 3): array
{
    $stmt = getPDO()->prepare(
        'SELECT a.title, a.body, a.created_at, u.username
         FROM announcements a
         LEFT JOIN users u ON u.user_id = a.created_by
         ORDER BY a.created_at DESC, a.ann_id DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Latest eco tips for participant-facing widgets.
 */
function getRecentEcoTips(int $limit = 3): array
{
    $stmt = getPDO()->prepare(
        'SELECT t.title, t.body, t.created_at, u.username
         FROM eco_tips t
         LEFT JOIN users u ON u.user_id = t.created_by
         ORDER BY t.created_at DESC, t.tip_id DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * One page of the participant table shown to moderators and admins.
 *
 * The page's user ids are picked first, then their figures are counted for
 * those rows only, each through an index on user_id. Grouping or joining the
 * whole activity, badge and check-in tables would cost more every day the
 * platform is used, for a page that only ever shows a handful of rows.
 *
 * @param int $page Requested page; out-of-range values are clamped
 * @return array{rows: array, total: int, page: int, pages: int}
 */
function getParticipantDirectory(string $search, int $page, int $perPage): array
{
    $pdo = getPDO();
    $where = 'u.role = "participant"';
    $params = [];

    if ($search !== '') {
        $where .= ' AND (u.username LIKE ? OR u.email LIKE ?)';
        $like = likeContains($search);
        $params = [$like, $like];
    }

    $count = $pdo->prepare('SELECT COUNT(*) FROM users u WHERE ' . $where);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min(max(1, $page), $pages);

    $idStmt = $pdo->prepare(
        'SELECT u.user_id FROM users u WHERE ' . $where . '
         ORDER BY u.points DESC, u.username ASC
         LIMIT ? OFFSET ?'
    );
    $position = 1;
    foreach ($params as $param) {
        $idStmt->bindValue($position++, $param);
    }
    $idStmt->bindValue($position++, $perPage, PDO::PARAM_INT);
    $idStmt->bindValue($position, ($page - 1) * $perPage, PDO::PARAM_INT);
    $idStmt->execute();
    $ids = array_map('intval', $idStmt->fetchAll(PDO::FETCH_COLUMN));

    if (!$ids) {
        return ['rows' => [], 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.username, u.email, u.points, u.streak, u.created_at,
                (SELECT COUNT(*) FROM activity_logs al
                 WHERE al.user_id = u.user_id AND al.status = "approved") AS approved_logs,
                (SELECT MAX(al.created_at) FROM activity_logs al
                 WHERE al.user_id = u.user_id AND al.status = "approved") AS last_approved_at,
                (SELECT COUNT(*) FROM user_badges ub WHERE ub.user_id = u.user_id) AS badge_count,
                (SELECT MAX(dc.checkin_date) FROM daily_checkins dc WHERE dc.user_id = u.user_id) AS last_checkin
         FROM users u
         WHERE u.user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
         ORDER BY u.points DESC, u.username ASC'
    );
    $stmt->execute($ids);

    return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}
