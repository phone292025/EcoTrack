<?php
/**
 * Small helpers for setting up rows directly, for tests that call the
 * domain functions in-process rather than going through HTTP.
 */
final class Fixtures
{
    public static function user(string $username, string $role = 'participant', int $points = 0): int
    {
        $pdo = getPDO();
        $pdo->prepare('INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)')
            ->execute([$username, $username . '@example.test', password_hash('Password1', PASSWORD_DEFAULT), $role]);
        $userId = (int)$pdo->lastInsertId();

        if ($points > 0) {
            awardPoints($userId, $points, 'adjustment', 'Test starting balance');
        }

        return $userId;
    }

    public static function userId(string $username): int
    {
        $stmt = getPDO()->prepare('SELECT user_id FROM users WHERE username = ?');
        $stmt->execute([$username]);

        return (int)$stmt->fetchColumn();
    }

    public static function moderatorId(): int
    {
        return self::userId('moderator');
    }

    public static function adminId(): int
    {
        return self::userId('admin');
    }

    /**
     * A pending activity log, optionally backdated by $daysAgo days.
     */
    public static function pendingLog(int $userId, int $daysAgo = 0, int $catId = 1): int
    {
        $pdo = getPDO();
        $pdo->prepare(
            'INSERT INTO activity_logs (user_id, cat_id, description, points, status, created_at)
             VALUES (?, ?, "Test activity description", ?, "pending", DATE_SUB(NOW(), INTERVAL ? DAY))'
        )->execute([$userId, $catId, POINTS_PER_ACTIVITY, $daysAgo]);

        return (int)$pdo->lastInsertId();
    }

    /** A check-in $daysAgo days before today (database clock). */
    public static function checkin(int $userId, int $daysAgo): void
    {
        getPDO()->prepare(
            'INSERT INTO daily_checkins (user_id, checkin_date) VALUES (?, DATE_SUB(CURDATE(), INTERVAL ? DAY))'
        )->execute([$userId, $daysAgo]);
    }

    public static function balance(int $userId): int
    {
        $stmt = getPDO()->prepare('SELECT points FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int)$stmt->fetchColumn();
    }

    public static function ledgerTotal(int $userId): int
    {
        $stmt = getPDO()->prepare('SELECT COALESCE(SUM(delta), 0) FROM points_transactions WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int)$stmt->fetchColumn();
    }

    /** Sum of ledger deltas of one kind. */
    public static function ledgerKind(int $userId, string $kind): int
    {
        $stmt = getPDO()->prepare('SELECT COALESCE(SUM(delta), 0) FROM points_transactions WHERE user_id = ? AND kind = ?');
        $stmt->execute([$userId, $kind]);

        return (int)$stmt->fetchColumn();
    }

    /** Move every ledger row and goal for a user back in time. */
    public static function ageEverything(int $userId, int $minutes): void
    {
        $pdo = getPDO();
        $pdo->prepare('UPDATE points_transactions SET created_at = DATE_SUB(created_at, INTERVAL ? MINUTE) WHERE user_id = ?')
            ->execute([$minutes, $userId]);
        $pdo->prepare('UPDATE goals SET created_at = DATE_SUB(created_at, INTERVAL ? MINUTE) WHERE user_id = ?')
            ->execute([$minutes, $userId]);
    }
}
