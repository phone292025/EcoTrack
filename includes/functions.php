<?php
/**
 * EcoTrack — Core Business Logic
 * File: includes/functions.php
 *
 * Requires: includes/db.php  (getPDO())
 *
 * Functions in this file:
 *   awardPoints()            — credit/debit points + log ledger
 *   updateStreak()           — increment or reset daily streak
 *   checkAndAwardBadges()    — evaluate all badge criteria for a user
 *   getUserGoalProgress()    — current goal + % complete
 *   getLeaderboard()         — ranked list of participants
 *   getCategoryBreakdown()   — points per category (for donut chart)
 *   getCO2Savings()          — cumulative CO2 data points (for line chart)
 *   getEcoImpactSummary()    — human-readable impact stats
 *   sanitise()               — XSS-safe output helper
 *   handleFileUpload()       — secure evidence file upload
 *   jsonResponse()           — standardised AJAX JSON output
 */

require_once __DIR__ . '/db.php';

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
 * @param bool  $success
 * @param array $data    Extra key-value pairs merged into response
 */
function jsonResponse(bool $success, array $data = []): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success], $data));
    exit;
}

/* =============================================================
 *  POINTS LEDGER
 * ============================================================*/

/**
 * Award (positive delta) or deduct (negative delta) points.
 * Updates users.points and appends to points_transactions.
 *
 * @param int    $userId
 * @param int    $delta    Positive to add, negative to deduct
 * @param string $reason   Human-readable reason stored in ledger
 * @param int|null $refId  Optional FK reference (log_id, redemption_id…)
 */
function awardPoints(int $userId, int $delta, string $reason, ?int $refId = null): void
{
    $pdo = getPDO();

    // Update user balance
    $stmt = $pdo->prepare(
        'UPDATE users SET points = GREATEST(0, points + :delta) WHERE user_id = :uid'
    );
    $stmt->execute([':delta' => $delta, ':uid' => $userId]);

    // Append ledger entry
    $stmt = $pdo->prepare(
        'INSERT INTO points_transactions (user_id, delta, reason, ref_id)
         VALUES (:uid, :delta, :reason, :ref)'
    );
    $stmt->execute([
        ':uid'    => $userId,
        ':delta'  => $delta,
        ':reason' => $reason,
        ':ref'    => $refId,
    ]);

    // Check badges after every points change
    checkAndAwardBadges($userId);
}

/* =============================================================
 *  STREAK MANAGEMENT
 * ============================================================*/

/**
 * Call after any approved activity log or check-in.
 * Increments streak if last activity was yesterday;
 * resets to 1 if there was a gap.
 * Awards bonus points at 3-day (5pts) and 7-day (15pts) milestones.
 */
function updateStreak(int $userId): void
{
    $pdo  = getPDO();
    $stmt = $pdo->prepare('SELECT streak, last_checkin FROM users WHERE user_id = :uid');
    $stmt->execute([':uid' => $userId]);
    $user = $stmt->fetch();

    if (!$user) return;

    $today     = new DateTimeImmutable('today');
    $lastDate  = $user['last_checkin'] ? new DateTimeImmutable($user['last_checkin']) : null;
    $newStreak = 1;

    if ($lastDate !== null) {
        $diff = (int)$lastDate->diff($today)->days;
        if ($diff === 0) {
            // Already checked in today — no change
            return;
        } elseif ($diff === 1) {
            // Consecutive day
            $newStreak = (int)$user['streak'] + 1;
        }
        // else gap > 1 day → reset to 1
    }

    $stmt = $pdo->prepare(
        'UPDATE users SET streak = :streak, last_checkin = :today WHERE user_id = :uid'
    );
    $stmt->execute([
        ':streak' => $newStreak,
        ':today'  => $today->format('Y-m-d'),
        ':uid'    => $userId,
    ]);

    // Streak bonus points
    if ($newStreak === 3)  awardPoints($userId, 5,  '3-Day Streak Bonus');
    if ($newStreak === 7)  awardPoints($userId, 15, '7-Day Streak Bonus');
    if ($newStreak === 30) awardPoints($userId, 50, '30-Day Streak Bonus');
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
 *   "goal_achieved" — handled separately when goal is met
 */
function checkAndAwardBadges(int $userId): void
{
    $pdo = getPDO();

    // Get current user stats
    $stmt = $pdo->prepare(
        'SELECT u.points, u.streak,
                (SELECT COUNT(*) FROM activity_logs
                 WHERE user_id = :uid AND status = "approved") AS log_count
         FROM users u WHERE u.user_id = :uid2'
    );
    $stmt->execute([':uid' => $userId, ':uid2' => $userId]);
    $stats = $stmt->fetch();
    if (!$stats) return;

    // Badges already earned
    $stmt = $pdo->prepare(
        'SELECT badge_id FROM user_badges WHERE user_id = :uid'
    );
    $stmt->execute([':uid' => $userId]);
    $earned = array_column($stmt->fetchAll(), 'badge_id');

    // All badges
    $allBadges = $pdo->query('SELECT * FROM badges')->fetchAll();

    foreach ($allBadges as $badge) {
        if (in_array($badge['badge_id'], $earned, true)) continue;

        $criteria = trim($badge['criteria'] ?? '');
        $award    = false;

        if (preg_match('/^points>=(\d+)$/', $criteria, $m)) {
            $award = (int)$stats['points'] >= (int)$m[1];
        } elseif (preg_match('/^streak>=(\d+)$/', $criteria, $m)) {
            $award = (int)$stats['streak'] >= (int)$m[1];
        } elseif (preg_match('/^logs>=(\d+)$/', $criteria, $m)) {
            $award = (int)$stats['log_count'] >= (int)$m[1];
        }
        // "goal_achieved" is triggered manually in goal-check logic

        if ($award) {
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (:uid, :bid)'
            );
            $stmt->execute([':uid' => $userId, ':bid' => $badge['badge_id']]);
        }
    }
}

/* =============================================================
 *  GOAL PROGRESS
 * ============================================================*/

/**
 * Returns the user's active goal and their progress toward it.
 *
 * @return array {goal_id, target, period, start_date, end_date,
 *               points_in_period, percent, days_left}
 *         or empty array if no active goal.
 */
function getUserGoalProgress(int $userId): array
{
    $pdo  = getPDO();
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
    if (!$goal) return [];

    // Points earned in period (approved logs only)
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
    $earned  = (int)$stmt->fetchColumn();
    $percent = min(100, (int)round(($earned / max(1, $goal['target'])) * 100));
    $daysLeft= (int)(new DateTimeImmutable($goal['end_date']))
                    ->diff(new DateTimeImmutable('today'))->days;

    // Award Goal Crusher badge if newly reached
    if ($percent >= 100 && !$goal['bonus_awarded']) {
        $pdo->prepare(
            'UPDATE goals SET bonus_awarded = 1 WHERE goal_id = :gid'
        )->execute([':gid' => $goal['goal_id']]);

        // Find "goal_achieved" badge
        $b = $pdo->query(
            "SELECT badge_id FROM badges WHERE criteria = 'goal_achieved' LIMIT 1"
        )->fetch();
        if ($b) {
            $pdo->prepare(
                'INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?, ?)'
            )->execute([$userId, $b['badge_id']]);
        }
        awardPoints($userId, 25, 'Goal Achieved Bonus');
    }

    return array_merge($goal, [
        'points_in_period' => $earned,
        'percent'          => $percent,
        'days_left'        => $daysLeft,
    ]);
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

    // Add rank number
    foreach ($rows as $i => &$row) {
        $row['rank'] = $i + 1;
    }
    return $rows;
}

/* =============================================================
 *  CATEGORY BREAKDOWN  (for Chart.js donut)
 * ============================================================*/

/**
 * Returns points earned per category by this user (approved logs only).
 *
 * @return array  {labels: [...], data: [...], colors: [...]}
 *                ready to pass directly to Chart.js
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
    // Accessible, eco-themed palette
    $colors = ['#2d936c', '#f4a261', '#457b9d', '#6d4c41'];

    foreach ($rows as $i => $row) {
        $labels[] = $row['name'];
        $data[]   = (int)$row['total'];
    }

    return [
        'labels' => $labels,
        'data'   => $data,
        'colors' => array_slice($colors, 0, count($labels)),
    ];
}

/* =============================================================
 *  CO2 SAVINGS DATA  (for line chart)
 * ============================================================*/

/**
 * Returns cumulative CO2 savings over time for Chart.js line chart.
 * Uses: 1 point = 0.01 kg CO2 (from category default; weighted average used here).
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
 * Converts total approved points into human-readable impact stats.
 *
 * @return array {co2_kg, plastic_bottles, trees_equivalent}
 */
function getEcoImpactSummary(int $userId): array
{
    $pdo  = getPDO();
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(points), 0) AS total
         FROM activity_logs
         WHERE user_id = :uid AND status = "approved"'
    );
    $stmt->execute([':uid' => $userId]);
    $points = (int)$stmt->fetchColumn();

    return [
        'co2_kg'           => round($points * 0.01,  2),  // 1 pt = 0.01 kg CO2
        'plastic_bottles'  => round($points * 0.05,  1),  // 1 pt ≈ 0.05 bottles avoided
        'trees_equivalent' => round($points / 500,   2),  // 500 pts ≈ 1 tree/year
    ];
}

/* =============================================================
 *  SECURE FILE UPLOAD
 * ============================================================*/

/**
 * Validates and saves an uploaded evidence file.
 * Stores files in: uploads/evidence/<uuid>.<ext>
 *
 * @param array  $file   $_FILES['evidence']
 * @return string|null   Stored filename on success, null on failure
 */
function handleFileUpload(array $file): ?string
{
    if ($file['error'] !== UPLOAD_ERR_OK) return null;

    $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowedExt  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $maxSize     = 5 * 1024 * 1024; // 5 MB

    // Check file size
    if ($file['size'] > $maxSize) return null;

    // Validate MIME via finfo (not $_FILES['type'] — that is user-supplied)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedMime, true)) return null;

    // Validate extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) return null;

    // Generate a safe random filename
    $newName = bin2hex(random_bytes(16)) . '.' . $ext;
    $destDir = __DIR__ . '/../uploads/evidence/';
    $destPath= $destDir . $newName;

    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

    if (!move_uploaded_file($file['tmp_name'], $destPath)) return null;

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
    $pdo  = getPDO();
    $today = date('Y-m-d');

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO daily_checkins (user_id, checkin_date) VALUES (:uid, :today)'
        );
        $stmt->execute([':uid' => $userId, ':today' => $today]);

        // Award 5 bonus points
        awardPoints($userId, 5, 'Daily Check-in');
        updateStreak($userId);

        return true;

    } catch (PDOException $e) {
        // Duplicate key = already checked in today
        if ($e->getCode() === '23000') return false;
        throw $e; // Re-throw unexpected errors
    }
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
 * @return array [{badge_id, name, description, icon, criteria, earned (bool), earned_at}]
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
 * Get paginated activity log for a user.
 *
 * @return array [{log_id, cat_name, description, points, status, created_at}]
 */
function getUserActivityLog(int $userId, int $page = 1, int $perPage = 10): array
{
    $offset = ($page - 1) * $perPage;
    $stmt   = getPDO()->prepare(
        'SELECT al.log_id, c.name AS cat_name, al.description,
                al.points, al.status, al.evidence, al.created_at
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
 *
 * @return array [{delta, reason, created_at}]
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

/**
 * Auto-complete joined challenges once the participant has at least one
 * approved matching activity log after joining the challenge.
 */
function refreshUserChallengeProgress(int $userId): void
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT cp.id, cp.challenge_id, cp.joined_at, cp.completed,
                c.title, c.points, c.cat_id, c.start_date, c.end_date, c.status
         FROM challenge_participants cp
         JOIN challenges c ON c.challenge_id = cp.challenge_id
         WHERE cp.user_id = :uid
           AND cp.completed = 0
           AND c.status IN ("active", "closed")'
    );
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $sql = 'SELECT al.log_id
                FROM activity_logs al
                WHERE al.user_id = :uid
                  AND al.status = "approved"
                  AND al.created_at >= :joined';
        $params = [
            ':uid' => $userId,
            ':joined' => $row['joined_at'],
        ];

        if (!empty($row['cat_id'])) {
            $sql .= ' AND al.cat_id = :cat_id';
            $params[':cat_id'] = (int)$row['cat_id'];
        }
        if (!empty($row['start_date'])) {
            $sql .= ' AND DATE(al.created_at) >= :start_date';
            $params[':start_date'] = $row['start_date'];
        }
        if (!empty($row['end_date'])) {
            $sql .= ' AND DATE(al.created_at) <= :end_date';
            $params[':end_date'] = $row['end_date'];
        }

        $sql .= ' ORDER BY al.created_at ASC LIMIT 1';
        $matchStmt = $pdo->prepare($sql);
        $matchStmt->execute($params);
        $matchingLogId = $matchStmt->fetchColumn();

        if (!$matchingLogId) {
            continue;
        }

        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $lockStmt = $pdo->prepare('SELECT completed FROM challenge_participants WHERE id = ? FOR UPDATE');
            $lockStmt->execute([(int)$row['id']]);
            $isCompleted = (int)$lockStmt->fetchColumn();

            if ($isCompleted === 0) {
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

            if ($startedTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
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
