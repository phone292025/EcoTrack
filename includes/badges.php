<?php
/**
 * EcoTrack — Badge engine.
 *
 * A badge's criteria string decides how it is earned:
 *   "points>=N"      the user's points balance
 *   "streak>=N"      the current streak, in days
 *   "logs>=N"        approved activity logs
 *   "goal_achieved"  paid by applyGoalCompletion() when a goal is met
 *   empty            awarded by hand from the admin badge page
 */

const BADGE_THRESHOLD_PATTERN = '/^(points|streak|logs)>=(\d{1,9})$/';

/** Is this a criteria string the engine understands? Empty means manual. */
function isValidBadgeCriteria(string $criteria): bool
{
    return $criteria === ''
        || $criteria === 'goal_achieved'
        || preg_match(BADGE_THRESHOLD_PATTERN, $criteria) === 1;
}

/**
 * Does a user with these stats meet a threshold criteria string?
 *
 * @param array{points: int|string, streak: int|string, log_count: int|string} $stats
 */
function badgeCriteriaMet(string $criteria, array $stats): bool
{
    if (!preg_match(BADGE_THRESHOLD_PATTERN, trim($criteria), $m)) {
        return false;
    }

    $value = match ($m[1]) {
        'points' => (int)$stats['points'],
        'streak' => (int)$stats['streak'],
        'logs'   => (int)$stats['log_count'],
    };

    return $value >= (int)$m[2];
}

/**
 * Evaluate every threshold badge the user has not earned yet and award the
 * ones they now qualify for.
 */
function checkAndAwardBadges(int $userId): void
{
    $pdo = getPDO();

    $stmt = $pdo->prepare(
        'SELECT u.points, u.streak,
                (SELECT COUNT(*) FROM activity_logs
                 WHERE user_id = ? AND status = "approved") AS log_count
         FROM users u WHERE u.user_id = ?'
    );
    $stmt->execute([$userId, $userId]);
    $stats = $stmt->fetch();
    if (!$stats) {
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT b.badge_id, b.criteria
         FROM badges b
         LEFT JOIN user_badges ub ON ub.badge_id = b.badge_id AND ub.user_id = ?
         WHERE ub.id IS NULL
           AND b.criteria IS NOT NULL
           AND b.criteria <> ""'
    );
    $stmt->execute([$userId]);

    foreach ($stmt->fetchAll() as $badge) {
        if (badgeCriteriaMet((string)$badge['criteria'], $stats)) {
            grantBadge($userId, (int)$badge['badge_id']);
        }
    }
}

/**
 * Give a badge to a user. Returns false if they already had it.
 */
function grantBadge(int $userId, int $badgeId): bool
{
    $stmt = getPDO()->prepare('INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?, ?)');
    $stmt->execute([$userId, $badgeId]);

    return $stmt->rowCount() > 0;
}

/**
 * Take a badge away from a user. Returns false if they did not have it.
 */
function revokeBadge(int $userId, int $badgeId): bool
{
    $stmt = getPDO()->prepare('DELETE FROM user_badges WHERE user_id = ? AND badge_id = ?');
    $stmt->execute([$userId, $badgeId]);

    return $stmt->rowCount() > 0;
}

/**
 * Award a badge to every participant who already meets its criteria. Run
 * after a badge is created or its criteria change, so people who qualified
 * earlier are not left waiting for their next points change.
 *
 * @return int Number of participants newly awarded the badge
 */
function awardBadgeToQualifiedUsers(int $badgeId): int
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT criteria FROM badges WHERE badge_id = ?');
    $stmt->execute([$badgeId]);
    $criteria = trim((string)$stmt->fetchColumn());

    if ($criteria === 'goal_achieved') {
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO user_badges (user_id, badge_id)
             SELECT DISTINCT g.user_id, ? FROM goals g WHERE g.bonus_awarded = 1'
        );
        $insert->execute([$badgeId]);
        return $insert->rowCount();
    }

    if (!preg_match(BADGE_THRESHOLD_PATTERN, $criteria, $m)) {
        return 0;
    }

    $threshold = (int)$m[2];
    $sql = match ($m[1]) {
        'points' => 'SELECT user_id, ? FROM users WHERE role = "participant" AND points >= ?',
        'streak' => 'SELECT user_id, ? FROM users WHERE role = "participant" AND streak >= ?',
        'logs'   => 'SELECT al.user_id, ? FROM activity_logs al
                     WHERE al.status = "approved"
                     GROUP BY al.user_id
                     HAVING COUNT(*) >= ?',
    };

    $insert = $pdo->prepare('INSERT IGNORE INTO user_badges (user_id, badge_id) ' . $sql);
    $insert->execute([$badgeId, $threshold]);

    return $insert->rowCount();
}

/**
 * Every badge, marked earned or locked for this user (for the badge gallery).
 *
 * @return array [{badge_id, name, description, icon, criteria, earned, earned_at}]
 */
function getUserBadges(int $userId): array
{
    $stmt = getPDO()->prepare(
        'SELECT b.badge_id, b.name, b.description, b.icon, b.criteria,
                IF(ub.user_id IS NOT NULL, 1, 0) AS earned,
                ub.earned_at
         FROM badges b
         LEFT JOIN user_badges ub ON ub.badge_id = b.badge_id AND ub.user_id = ?
         ORDER BY earned DESC, b.badge_id ASC'
    );
    $stmt->execute([$userId]);

    return $stmt->fetchAll();
}

/**
 * Turn a badge criteria string into something a participant can read, so a
 * locked badge explains how to unlock it.
 */
function describeBadgeCriteria(?string $criteria): string
{
    $criteria = trim((string)$criteria);

    if (preg_match(BADGE_THRESHOLD_PATTERN, $criteria, $m)) {
        $n = (int)$m[2];
        return match ($m[1]) {
            'points' => 'Reach ' . $n . ' points',
            'streak' => 'Stay active ' . $n . ' days in a row',
            'logs'   => 'Get ' . $n . ' ' . ($n === 1 ? 'activity' : 'activities') . ' approved',
        };
    }
    if ($criteria === 'goal_achieved') {
        return 'Hit one of your personal point goals';
    }

    return 'Awarded by an administrator';
}
