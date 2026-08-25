<?php
/**
 * EcoTrack — Core Business Logic
 * File: includes/functions.php
 *
 * Requires: database/db.php  (getPDO())
 *
 * Reading vs writing
 * ------------------
 * Functions named get*() only read. Anything that awards points, badges or
 * challenge completions is a write, is named with an apply/award/record
 * prefix, and is only ever called from a POST handler — never from a render.
 */

require_once __DIR__ . '/../database/db.php';

/* =============================================================
 *  OUTPUT HELPERS
 * ============================================================*/

/**
 * Escape a string for safe HTML output.
 * Always use this when printing user-supplied data.
 */
function sanitise(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Terminate with a JSON response (for AJAX endpoints).
 *
 * @param bool  $success
 * @param array $data    Extra key-value pairs merged into response
 * @param int   $status  HTTP status code
 */
function jsonResponse(bool $success, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success], $data));
    exit;
}

/**
 * Evenly spaced colours for however many chart slices exist, so adding a
 * category can never leave a slice uncoloured.
 */
function chartColors(int $count): array
{
    $preset = ['#2d936c', '#f4a261', '#457b9d', '#7b5ea7', '#e07c30', '#3aa17e'];

    if ($count <= count($preset)) {
        return array_slice($preset, 0, max(0, $count));
    }

    $colors = $preset;
    for ($i = count($preset); $i < $count; $i++) {
        $hue = (int)round(($i * 360) / $count);
        $colors[] = sprintf('hsl(%d, 45%%, 45%%)', $hue);
    }

    return $colors;
}

/* =============================================================
 *  POINTS LEDGER
 *
 *  points_transactions is authoritative. users.points is a cached running
 *  total of it, and the two are always written together in one transaction
 *  so they cannot drift apart.
 * ============================================================*/

/**
 * Award (positive delta) or deduct (negative delta) points.
 *
 * A deduction that would take the balance below zero is rejected rather than
 * silently clamped, because clamping the balance while writing the full delta
 * to the ledger is exactly what makes the two disagree.
 *
 * @param  int      $userId
 * @param  int      $delta   Positive to add, negative to deduct
 * @param  string   $reason  Human-readable reason stored in ledger
 * @param  int|null $refId   Optional FK reference (log_id, redemption_id…)
 * @return bool              False when the balance is too low to deduct
 */
function awardPoints(int $userId, int $delta, string $reason, ?int $refId = null): bool
{
    $pdo = getPDO();

    if ($delta === 0) {
        return true;
    }

    // Join an outer transaction when one is already open so the caller keeps
    // control of the commit boundary.
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare('SELECT points FROM users WHERE user_id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $current = $stmt->fetchColumn();

        if ($current === false) {
            if ($ownTransaction) {
                $pdo->rollBack();
            }
            return false;
        }

        $newBalance = (int)$current + $delta;

        if ($newBalance < 0) {
            if ($ownTransaction) {
                $pdo->rollBack();
            }
            return false;
        }

        $pdo->prepare('UPDATE users SET points = ? WHERE user_id = ?')
            ->execute([$newBalance, $userId]);

        $pdo->prepare(
            'INSERT INTO points_transactions (user_id, delta, reason, ref_id)
             VALUES (:uid, :delta, :reason, :ref)'
        )->execute([
            ':uid'    => $userId,
            ':delta'  => $delta,
            ':reason' => $reason,
            ':ref'    => $refId,
        ]);

        checkAndAwardBadges($userId);

        if ($ownTransaction) {
            $pdo->commit();
        }

        // Keep the cached badge in the navigation honest.
        if (function_exists('currentUserId') && $userId === currentUserId()) {
            $_SESSION['points'] = $newBalance;
        }

        return true;
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/* =============================================================
 *  STREAK MANAGEMENT
 * ============================================================*/

/**
 * Rebuild the streak from the dates the participant was actually active,
 * rather than incrementing a counter whenever a moderator happens to click
 * approve. Counts a day as active if it has an approved activity log or a
 * daily check-in.
 *
 * The streak stays alive if the most recent active day is today or
 * yesterday; any longer gap resets it to zero.
 *
 * @return int The recalculated streak
 */
function recalculateStreak(int $userId): int
{
    $pdo = getPDO();

    $stmt = $pdo->prepare(
        'SELECT DISTINCT active_date FROM (
             SELECT DATE(created_at) AS active_date
             FROM activity_logs
             WHERE user_id = :uid AND status = "approved"
             UNION
             SELECT checkin_date AS active_date
             FROM daily_checkins
             WHERE user_id = :uid2
         ) AS days
         ORDER BY active_date DESC
         LIMIT 400'
    );
    $stmt->execute([':uid' => $userId, ':uid2' => $userId]);
    $dates = array_column($stmt->fetchAll(), 'active_date');

    $streak = 0;
    $lastActive = $dates[0] ?? null;

    if ($lastActive !== null) {
        $today = new DateTimeImmutable('today');
        $mostRecent = new DateTimeImmutable($lastActive);
        $gap = (int)$today->diff($mostRecent)->days;

        // Only today or yesterday keeps a streak running.
        if ($mostRecent <= $today && $gap <= 1) {
            $expected = $mostRecent;
            foreach ($dates as $date) {
                if ($date === $expected->format('Y-m-d')) {
                    $streak++;
                    $expected = $expected->modify('-1 day');
                    continue;
                }
                break;
            }
        }
    }

    $pdo->prepare('UPDATE users SET streak = ?, last_checkin = ? WHERE user_id = ?')
        ->execute([$streak, $lastActive, $userId]);

    return $streak;
}

/**
 * Recalculate the streak and pay any milestone bonus not already paid.
 * Call from write paths only (approval, check-in).
 */
function applyStreakBonuses(int $userId): void
{
    $streak = recalculateStreak($userId);

    $milestones = [3 => 5, 7 => 15, 30 => 50];
    if (!isset($milestones[$streak])) {
        return;
    }

    $reason = $streak . '-Day Streak Bonus';

    // Bonuses are milestone-based, so pay each one at most once per streak run.
    $stmt = getPDO()->prepare(
        'SELECT COUNT(*)
         FROM points_transactions
         WHERE user_id = ?
           AND reason = ?
           AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)'
    );
    $stmt->execute([$userId, $reason, $streak]);

    if ((int)$stmt->fetchColumn() === 0) {
        awardPoints($userId, $milestones[$streak], $reason);
    }
}

/* =============================================================
 *  BADGE ENGINE
 * ============================================================*/

/**
 * Evaluates all badge criteria for a user and awards any newly earned ones.
 * Criteria strings supported:
 *   "points>=N"   — user's total points
 *   "streak>=N"   — current streak
 *   "logs>=N"     — total approved activity logs
 *   "goal_achieved" — handled separately when a goal is met
 */
function checkAndAwardBadges(int $userId): void
{
    $pdo = getPDO();

    $stmt = $pdo->prepare(
        'SELECT u.points, u.streak,
                (SELECT COUNT(*) FROM activity_logs
                 WHERE user_id = :uid AND status = "approved") AS log_count
         FROM users u WHERE u.user_id = :uid2'
    );
    $stmt->execute([':uid' => $userId, ':uid2' => $userId]);
    $stats = $stmt->fetch();
    if (!$stats) {
        return;
    }

    // Only look at badges this user has not earned yet.
    $stmt = $pdo->prepare(
        'SELECT b.badge_id, b.criteria
         FROM badges b
         LEFT JOIN user_badges ub ON ub.badge_id = b.badge_id AND ub.user_id = :uid
         WHERE ub.id IS NULL
           AND b.criteria IS NOT NULL
           AND b.criteria <> ""'
    );
    $stmt->execute([':uid' => $userId]);
    $candidates = $stmt->fetchAll();

    if (!$candidates) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (:uid, :bid)'
    );

    foreach ($candidates as $badge) {
        $criteria = trim((string)$badge['criteria']);
        $award = false;

        if (preg_match('/^points>=(\d+)$/', $criteria, $m)) {
            $award = (int)$stats['points'] >= (int)$m[1];
        } elseif (preg_match('/^streak>=(\d+)$/', $criteria, $m)) {
            $award = (int)$stats['streak'] >= (int)$m[1];
        } elseif (preg_match('/^logs>=(\d+)$/', $criteria, $m)) {
            $award = (int)$stats['log_count'] >= (int)$m[1];
        }
        // "goal_achieved" is triggered by applyGoalCompletion()

        if ($award) {
            $insert->execute([':uid' => $userId, ':bid' => $badge['badge_id']]);
        }
    }
}

/* =============================================================
 *  GOAL PROGRESS
 * ============================================================*/

/**
 * Read the user's active goal and their progress toward it. Read-only.
 *
 * @return array {goal_id, target, period, start_date, end_date,
 *               points_in_period, percent, days_left}
 *         or empty array if no active goal.
 */
function getUserGoalProgress(int $userId): array
{
    $pdo = getPDO();
    $today = date('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT * FROM goals
         WHERE user_id = :uid
           AND start_date <= :today
           AND end_date   >= :today2
         ORDER BY goal_id DESC LIMIT 1'
    );
    $stmt->execute([':uid' => $userId, ':today' => $today, ':today2' => $today]);
    $goal = $stmt->fetch();
    if (!$goal) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(t.delta), 0) AS earned
         FROM points_transactions t
         WHERE t.user_id = :uid
           AND t.delta > 0
           AND t.created_at BETWEEN :start AND :end'
    );
    $stmt->execute([
        ':uid'   => $userId,
        ':start' => $goal['start_date'] . ' 00:00:00',
        ':end'   => $goal['end_date']   . ' 23:59:59',
    ]);
    $earned = (int)$stmt->fetchColumn();

    $percent  = min(100, (int)round(($earned / max(1, (int)$goal['target'])) * 100));
    $daysLeft = (int)(new DateTimeImmutable('today'))
        ->diff(new DateTimeImmutable($goal['end_date']))->days;

    return array_merge($goal, [
        'points_in_period' => $earned,
        'percent'          => $percent,
        'days_left'        => $daysLeft,
    ]);
}

/**
 * Pay the goal bonus if the active goal has just been met. Write path — call
 * after something changes the user's points, never during a page render.
 */
function applyGoalCompletion(int $userId): void
{
    $pdo = getPDO();
    $goal = getUserGoalProgress($userId);

    if (!$goal || $goal['percent'] < 100 || !empty($goal['bonus_awarded'])) {
        return;
    }

    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }

    try {
        // Re-check under a lock so two concurrent requests cannot both pay out.
        $stmt = $pdo->prepare('SELECT bonus_awarded FROM goals WHERE goal_id = ? FOR UPDATE');
        $stmt->execute([(int)$goal['goal_id']]);
        $alreadyAwarded = (int)$stmt->fetchColumn();

        if ($alreadyAwarded === 0) {
            $pdo->prepare('UPDATE goals SET bonus_awarded = 1 WHERE goal_id = ?')
                ->execute([(int)$goal['goal_id']]);

            $badge = $pdo->query(
                "SELECT badge_id FROM badges WHERE criteria = 'goal_achieved' LIMIT 1"
            )->fetch();

            if ($badge) {
                $pdo->prepare(
                    'INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?, ?)'
                )->execute([$userId, $badge['badge_id']]);
            }

            awardPoints($userId, 25, 'Goal Achieved Bonus', (int)$goal['goal_id']);
        }

        if ($ownTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/* =============================================================
 *  LEADERBOARD
 * ============================================================*/

/**
 * Returns top-N participants sorted by points descending.
 *
 * @return array  [{rank, user_id, username, points, streak, badge_count}]
 */
function getLeaderboard(int $limit = 20): array
{
    $pdo  = getPDO();
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.username, u.points, u.streak,
                (SELECT COUNT(*) FROM user_badges ub WHERE ub.user_id = u.user_id) AS badge_count
         FROM users u
         WHERE u.role = "participant"
         ORDER BY u.points DESC, u.username ASC
         LIMIT :lim'
    );
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Equal scores share a rank; the next distinct score skips ahead.
    $rank = 0;
    $seen = 0;
    $previousPoints = null;

    foreach ($rows as $i => &$row) {
        $seen++;
        $points = (int)$row['points'];
        if ($points !== $previousPoints) {
            $rank = $seen;
            $previousPoints = $points;
        }
        $row['rank'] = $rank;
    }
    unset($row);

    return $rows;
}

/* =============================================================
 *  CATEGORY BREAKDOWN  (for Chart.js donut)
 * ============================================================*/

/**
 * Returns points earned per category by this user (approved logs only).
 *
 * @return array  {labels: [...], data: [...], colors: [...]}
 */
function getCategoryBreakdown(int $userId): array
{
    $pdo  = getPDO();
    $stmt = $pdo->prepare(
        'SELECT c.name, COALESCE(SUM(al.points), 0) AS total
         FROM categories c
         LEFT JOIN activity_logs al
               ON al.cat_id = c.cat_id
              AND al.user_id = :uid
              AND al.status  = "approved"
         GROUP BY c.cat_id, c.name
         ORDER BY c.cat_id'
    );
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll();

    $labels = [];
    $data   = [];

    foreach ($rows as $row) {
        $labels[] = $row['name'];
        $data[]   = (int)$row['total'];
    }

    return [
        'labels' => $labels,
        'data'   => $data,
        'colors' => chartColors(count($labels)),
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
         WHERE al.user_id = :uid AND al.status = "approved"'
    );
    $stmt->execute([':uid' => $userId]);

    return (float)$stmt->fetchColumn();
}

/**
 * Returns cumulative CO2 savings over time for the Chart.js line chart.
 *
 * @return array {labels: ['2025-01-01',...], data: [0.10, 0.25,...]}
 */
function getCO2Savings(int $userId): array
{
    $pdo  = getPDO();
    $stmt = $pdo->prepare(
        'SELECT DATE(al.created_at) AS log_date,
                SUM(al.points * c.co2_per_point) AS co2_day
         FROM activity_logs al
         JOIN categories c ON c.cat_id = al.cat_id
         WHERE al.user_id = :uid AND al.status = "approved"
         GROUP BY DATE(al.created_at)
         ORDER BY log_date ASC'
    );
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll();

    $labels     = [];
    $data       = [];
    $cumulative = 0.0;

    foreach ($rows as $row) {
        $labels[]    = $row['log_date'];
        $cumulative += (float)$row['co2_day'];
        $data[]      = round($cumulative, 3);
    }

    return ['labels' => $labels, 'data' => $data];
}

/* =============================================================
 *  ECO IMPACT SUMMARY  (dashboard widget text)
 * ============================================================*/

/**
 * Human-readable impact stats. CO2 uses the same category-weighted figure as
 * the chart, so the tile and the graph beside it always agree.
 *
 * @return array {co2_kg, plastic_bottles, trees_equivalent}
 */
function getEcoImpactSummary(int $userId): array
{
    $stmt = getPDO()->prepare(
        'SELECT COALESCE(SUM(points), 0) AS total
         FROM activity_logs
         WHERE user_id = :uid AND status = "approved"'
    );
    $stmt->execute([':uid' => $userId]);
    $points = (int)$stmt->fetchColumn();

    $co2 = getUserCo2Kg($userId);

    return [
        'co2_kg'           => round($co2, 2),
        'plastic_bottles'  => round($points * 0.05, 1),   // 1 pt ≈ 0.05 bottles avoided
        'trees_equivalent' => round($co2 / 21, 3),        // ~21 kg CO2 per tree-year
    ];
}

/* =============================================================
 *  SECURE FILE UPLOAD
 * ============================================================*/

/**
 * Validates and saves an uploaded image.
 *
 * @param  array  $file      $_FILES['evidence']
 * @param  string $subdir    Folder under uploads/ to store in
 * @return string|null       Stored filename on success, null on failure
 */
function handleFileUpload(array $file, string $subdir = 'evidence'): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    // Extension must agree with the sniffed MIME type, so a .png that is
    // actually a JPEG cannot slip through with a mismatched name.
    $mimeToExt = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png'  => ['png'],
        'image/gif'  => ['gif'],
        'image/webp' => ['webp'],
    ];
    $maxSize = 5 * 1024 * 1024; // 5 MB

    if (($file['size'] ?? 0) > $maxSize || ($file['size'] ?? 0) <= 0) {
        return null;
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return null;
    }

    // Validate MIME via finfo, never $_FILES['type'] — that is user-supplied.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!isset($mimeToExt[$mime])) {
        return null;
    }

    // Confirm it really decodes as an image of that type.
    if (@getimagesize($file['tmp_name']) === false) {
        return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $mimeToExt[$mime], true)) {
        return null;
    }

    $subdir  = preg_replace('/[^a-z0-9_-]/i', '', $subdir) ?: 'evidence';
    $newName = bin2hex(random_bytes(16)) . '.' . $ext;
    $destDir = __DIR__ . '/../uploads/' . $subdir . '/';

    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        return null;
    }

    if (!move_uploaded_file($file['tmp_name'], $destDir . $newName)) {
        return null;
    }

    return $newName; // Store only filename in DB, never the full path
}

/* =============================================================
 *  DAILY CHECK-IN
 * ============================================================*/

/**
 * Performs a daily check-in for the user.
 * Returns true if check-in was new (points awarded),
 * false if already checked in today.
 */
function dailyCheckIn(int $userId): bool
{
    $pdo   = getPDO();
    $today = date('Y-m-d');

    $pdo->beginTransaction();

    try {
        $pdo->prepare(
            'INSERT INTO daily_checkins (user_id, checkin_date) VALUES (:uid, :today)'
        )->execute([':uid' => $userId, ':today' => $today]);

        awardPoints($userId, 5, 'Daily Check-in');

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Duplicate key means they already checked in today.
        if (isDuplicateKeyError($e)) {
            return false;
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // Streak and goal bonuses open their own transactions.
    applyStreakBonuses($userId);
    applyGoalCompletion($userId);

    return true;
}

/**
 * Has this user already checked in today? Lets the UI render the button in
 * the right state instead of making the user click to find out.
 */
function hasCheckedInToday(int $userId): bool
{
    $stmt = getPDO()->prepare(
        'SELECT COUNT(*) FROM daily_checkins WHERE user_id = ? AND checkin_date = CURDATE()'
    );
    $stmt->execute([$userId]);

    return (int)$stmt->fetchColumn() > 0;
}

/* =============================================================
 *  USER FETCH HELPERS
 * ============================================================*/

/**
 * Fetch a single user by ID. Returns false if not found.
 */
function getUserById(int $userId): array|false
{
    $stmt = getPDO()->prepare(
        'SELECT user_id, username, email, role, points, streak, last_checkin, avatar, created_at
         FROM users WHERE user_id = :uid'
    );
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetch();
}

/**
 * Get all badges for a user, including locked ones (for badge gallery).
 *
 * @return array [{badge_id, name, description, icon, criteria, earned, earned_at}]
 */
function getUserBadges(int $userId): array
{
    $pdo  = getPDO();
    $stmt = $pdo->prepare(
        'SELECT b.badge_id, b.name, b.description, b.icon, b.criteria,
                IF(ub.user_id IS NOT NULL, 1, 0) AS earned,
                ub.earned_at
         FROM badges b
         LEFT JOIN user_badges ub ON ub.badge_id = b.badge_id AND ub.user_id = :uid
         ORDER BY earned DESC, b.badge_id ASC'
    );
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll();
}

/**
 * Turn a badge criteria string into something a participant can read, so a
 * locked badge explains how to unlock it.
 */
function describeBadgeCriteria(?string $criteria): string
{
    $criteria = trim((string)$criteria);

    if (preg_match('/^points>=(\d+)$/', $criteria, $m)) {
        return 'Reach ' . (int)$m[1] . ' points';
    }
    if (preg_match('/^streak>=(\d+)$/', $criteria, $m)) {
        return 'Stay active ' . (int)$m[1] . ' days in a row';
    }
    if (preg_match('/^logs>=(\d+)$/', $criteria, $m)) {
        $n = (int)$m[1];
        return 'Get ' . $n . ' ' . ($n === 1 ? 'activity' : 'activities') . ' approved';
    }
    if ($criteria === 'goal_achieved') {
        return 'Hit one of your personal point goals';
    }

    return 'Awarded by an administrator';
}

/**
 * Get paginated activity log for a user.
 */
function getUserActivityLog(int $userId, int $page = 1, int $perPage = 10): array
{
    $offset = max(0, ($page - 1) * $perPage);
    $stmt   = getPDO()->prepare(
        'SELECT al.log_id, c.name AS cat_name, al.description,
                al.points, al.status, al.evidence, al.review_note, al.created_at
         FROM activity_logs al
         JOIN categories c ON c.cat_id = al.cat_id
         WHERE al.user_id = :uid
         ORDER BY al.created_at DESC
         LIMIT :lim OFFSET :off'
    );
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Get points transaction history for a user.
 */
function getPointsHistory(int $userId, int $limit = 50): array
{
    $stmt = getPDO()->prepare(
        'SELECT delta, reason, created_at
         FROM points_transactions
         WHERE user_id = :uid
         ORDER BY created_at DESC, txn_id DESC
         LIMIT :lim'
    );
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Lifetime earned and spent totals across every transaction, not just the
 * page of history being displayed.
 *
 * @return array{earned: int, spent: int}
 */
function getPointsTotals(int $userId): array
{
    $stmt = getPDO()->prepare(
        'SELECT COALESCE(SUM(CASE WHEN delta > 0 THEN delta ELSE 0 END), 0)  AS earned,
                COALESCE(SUM(CASE WHEN delta < 0 THEN -delta ELSE 0 END), 0) AS spent
         FROM points_transactions
         WHERE user_id = :uid'
    );
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch() ?: [];

    return [
        'earned' => (int)($row['earned'] ?? 0),
        'spent'  => (int)($row['spent']  ?? 0),
    ];
}

/**
 * Returns recent announcements for participant-facing widgets.
 */
function getRecentAnnouncements(int $limit = 3): array
{
    $stmt = getPDO()->prepare(
        'SELECT a.title, a.body, a.created_at, u.username
         FROM announcements a
         LEFT JOIN users u ON u.user_id = a.created_by
         ORDER BY a.created_at DESC
         LIMIT :lim'
    );
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Returns recent eco tips for participant-facing widgets.
 */
function getRecentEcoTips(int $limit = 3): array
{
    $stmt = getPDO()->prepare(
        'SELECT t.title, t.body, t.created_at, u.username
         FROM eco_tips t
         LEFT JOIN users u ON u.user_id = t.created_by
         ORDER BY t.created_at DESC
         LIMIT :lim'
    );
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/* =============================================================
 *  CHALLENGES
 * ============================================================*/

/**
 * How many approved logs a user has that count toward a challenge.
 * Read-only, so pages can show "3 of 5 logged".
 */
function countChallengeProgress(int $userId, array $challenge): int
{
    $sql = 'SELECT COUNT(*)
            FROM activity_logs al
            WHERE al.user_id = :uid
              AND al.status = "approved"
              AND al.created_at >= :joined';
    $params = [
        ':uid'    => $userId,
        ':joined' => $challenge['joined_at'],
    ];

    if (!empty($challenge['cat_id'])) {
        $sql .= ' AND al.cat_id = :cat_id';
        $params[':cat_id'] = (int)$challenge['cat_id'];
    }
    if (!empty($challenge['start_date'])) {
        $sql .= ' AND DATE(al.created_at) >= :start_date';
        $params[':start_date'] = $challenge['start_date'];
    }
    if (!empty($challenge['end_date'])) {
        $sql .= ' AND DATE(al.created_at) <= :end_date';
        $params[':end_date'] = $challenge['end_date'];
    }

    $stmt = getPDO()->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
}

/**
 * Progress toward every challenge this user has joined, keyed by challenge id.
 * Read-only — safe to call from a page render.
 *
 * @return array<int, array{done: int, target: int, completed: bool}>
 */
function getUserChallengeProgress(int $userId): array
{
    $stmt = getPDO()->prepare(
        'SELECT cp.challenge_id, cp.joined_at, cp.completed,
                c.cat_id, c.start_date, c.end_date, c.target_count
         FROM challenge_participants cp
         JOIN challenges c ON c.challenge_id = cp.challenge_id
         WHERE cp.user_id = :uid'
    );
    $stmt->execute([':uid' => $userId]);

    $progress = [];
    foreach ($stmt->fetchAll() as $row) {
        $target = max(1, (int)($row['target_count'] ?? 1));
        $done   = !empty($row['completed'])
            ? $target
            : min($target, countChallengeProgress($userId, $row));

        $progress[(int)$row['challenge_id']] = [
            'done'      => $done,
            'target'    => $target,
            'completed' => !empty($row['completed']),
        ];
    }

    return $progress;
}

/**
 * Complete any joined challenge whose target has been met, and pay its points.
 *
 * Write path — call after a submission is approved, never during a render.
 */
function refreshUserChallengeProgress(int $userId): void
{
    $pdo  = getPDO();
    $stmt = $pdo->prepare(
        'SELECT cp.id, cp.challenge_id, cp.joined_at, cp.completed,
                c.title, c.points, c.cat_id, c.start_date, c.end_date,
                c.status, c.target_count
         FROM challenge_participants cp
         JOIN challenges c ON c.challenge_id = cp.challenge_id
         WHERE cp.user_id = :uid
           AND cp.completed = 0
           AND c.status IN ("active", "closed")'
    );
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $target = max(1, (int)($row['target_count'] ?? 1));

        if (countChallengeProgress($userId, $row) < $target) {
            continue;
        }

        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $lock = $pdo->prepare('SELECT completed FROM challenge_participants WHERE id = ? FOR UPDATE');
            $lock->execute([(int)$row['id']]);

            if ((int)$lock->fetchColumn() === 0) {
                $pdo->prepare(
                    'UPDATE challenge_participants
                     SET completed = 1, completed_at = NOW()
                     WHERE id = ?'
                )->execute([(int)$row['id']]);

                awardPoints(
                    $userId,
                    (int)$row['points'],
                    'Challenge completed: ' . $row['title'],
                    (int)$row['challenge_id']
                );
            }

            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

/**
 * Quick participant challenge stats for dashboard/profile widgets.
 *
 * @return array {joined, completed}
 */
function getUserChallengeStats(int $userId): array
{
    $stmt = getPDO()->prepare(
        'SELECT COUNT(*) AS joined,
                COALESCE(SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END), 0) AS completed
         FROM challenge_participants
         WHERE user_id = :uid'
    );
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetch() ?: ['joined' => 0, 'completed' => 0];
}
