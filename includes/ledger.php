<?php
/**
 * EcoTrack — Points ledger, streaks, daily check-in and personal goals.
 *
 * points_transactions is authoritative. users.points is a cached running
 * total of it, and the two are always written together in one transaction
 * so they cannot drift apart.
 *
 * Reading vs writing: get*() functions only read. Anything that moves points
 * is named apply/award/save/daily and is only ever called from a POST handler,
 * never from a page render.
 */

/**
 * Run $work inside a transaction, or inside the caller's transaction when one
 * is already open so the caller keeps control of the commit boundary.
 *
 * @template T
 * @param callable(PDO): T $work
 * @return T
 */
function inTransaction(callable $work): mixed
{
    $pdo = getPDO();
    $own = !$pdo->inTransaction();

    if ($own) {
        $pdo->beginTransaction();
    }

    try {
        $result = $work($pdo);
        if ($own) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Lock a user's row until the surrounding transaction ends. Every write that
 * decides whether a bonus is due takes this lock first, so two requests for
 * the same user cannot both decide it is still unpaid.
 *
 * Lock order: a transaction takes the user's row before any other row of
 * theirs (goal, challenge entry, redemption, reward). Two transactions that
 * lock the same rows in opposite orders can deadlock.
 */
function lockUser(int $userId): ?array
{
    $stmt = getPDO()->prepare('SELECT user_id, points FROM users WHERE user_id = ? FOR UPDATE');
    $stmt->execute([$userId]);

    return $stmt->fetch() ?: null;
}

/* =============================================================
 *  POINTS
 * ============================================================*/

/**
 * Award (positive delta) or deduct (negative delta) points.
 *
 * A deduction that would take the balance below zero is rejected rather than
 * silently clamped, because clamping the balance while writing the full delta
 * to the ledger is exactly what makes the two disagree.
 *
 * @param string   $kind   One of LEDGER_KINDS
 * @param string   $reason Human-readable reason shown in the points history
 * @param int|null $refId  Related row: log_id, redemption_id, goal_id...
 * @return bool            False when the user is missing or the balance is too low
 */
function awardPoints(int $userId, int $delta, string $kind, string $reason, ?int $refId = null): bool
{
    if (!in_array($kind, LEDGER_KINDS, true)) {
        throw new InvalidArgumentException('Unknown ledger kind: ' . $kind);
    }

    if ($delta === 0) {
        return true;
    }

    $newBalance = inTransaction(function (PDO $pdo) use ($userId, $delta, $kind, $reason, $refId): ?int {
        $user = lockUser($userId);
        if ($user === null) {
            return null;
        }

        $newBalance = (int)$user['points'] + $delta;
        if ($newBalance < 0) {
            return null;
        }

        $pdo->prepare('UPDATE users SET points = ? WHERE user_id = ?')
            ->execute([$newBalance, $userId]);

        $pdo->prepare(
            'INSERT INTO points_transactions (user_id, delta, kind, reason, ref_id)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $delta, $kind, $reason, $refId]);

        checkAndAwardBadges($userId);

        return $newBalance;
    });

    if ($newBalance === null) {
        return false;
    }

    // Keep the balance shown in the navigation honest.
    if (function_exists('currentUserId') && $userId === currentUserId()) {
        $_SESSION['points'] = $newBalance;
    }

    return true;
}

/* =============================================================
 *  STREAKS
 * ============================================================*/

/**
 * Rebuild the streak from the dates the participant was actually active,
 * rather than incrementing a counter whenever a moderator happens to click
 * approve. A day counts as active if it has an approved activity log or a
 * daily check-in.
 *
 * The streak stays alive if the most recent active day is today or
 * yesterday; any longer gap resets it to zero.
 *
 * @return array{streak: int, last_active: ?string} last_active is Y-m-d
 */
function recalculateStreak(int $userId): array
{
    $pdo = getPDO();

    $stmt = $pdo->prepare(
        'SELECT DISTINCT active_date FROM (
             SELECT DATE(created_at) AS active_date
             FROM activity_logs
             WHERE user_id = ? AND status = "approved"
             UNION
             SELECT checkin_date AS active_date
             FROM daily_checkins
             WHERE user_id = ?
         ) AS days
         ORDER BY active_date DESC
         LIMIT 400'
    );
    $stmt->execute([$userId, $userId]);
    $dates = array_column($stmt->fetchAll(), 'active_date');

    $streak = 0;
    $lastActive = $dates[0] ?? null;

    if ($lastActive !== null) {
        // dbToday(), not PHP's clock — these dates were written by MySQL.
        $today = dbTodayObject();
        $mostRecent = new DateTimeImmutable($lastActive);
        $gap = (int)$today->diff($mostRecent)->days;

        // Only today or yesterday keeps a streak running.
        if ($mostRecent <= $today && $gap <= 1) {
            $expected = $mostRecent;
            foreach ($dates as $date) {
                if ($date !== $expected->format('Y-m-d')) {
                    break;
                }
                $streak++;
                $expected = $expected->modify('-1 day');
            }
        }
    }

    $pdo->prepare('UPDATE users SET streak = ?, last_checkin = ? WHERE user_id = ?')
        ->execute([$streak, $lastActive, $userId]);

    return ['streak' => $streak, 'last_active' => $lastActive];
}

/**
 * Recalculate the streak and pay every milestone the current run has reached
 * but not yet been paid for. Call from write paths only (approval, check-in).
 *
 * "Reached", not "equal to": a late approval can fill a gap and move a streak
 * from 2 straight to 4, and the 3-day bonus is still owed.
 */
function applyStreakBonuses(int $userId): void
{
    inTransaction(function (PDO $pdo) use ($userId): void {
        if (lockUser($userId) === null) {
            return;
        }

        ['streak' => $streak, 'last_active' => $lastActive] = recalculateStreak($userId);

        // awardPoints() checked badges against the streak as it was before
        // this recalculation, so check again now that it is current. Without
        // this a "streak>=5" badge waited for some later points change.
        checkAndAwardBadges($userId);

        if ($streak === 0 || $lastActive === null) {
            return;
        }

        // The first day of the current run. A bonus paid on or after it
        // belongs to this run; anything older belongs to a run that broke.
        $runStart = (new DateTimeImmutable($lastActive))
            ->modify('-' . ($streak - 1) . ' days')
            ->format('Y-m-d 00:00:00');

        $alreadyPaid = $pdo->prepare(
            'SELECT COUNT(*)
             FROM points_transactions
             WHERE user_id = ? AND kind = "streak_bonus" AND reason = ? AND created_at >= ?'
        );

        foreach (STREAK_BONUSES as $days => $bonus) {
            if ($streak < $days) {
                continue;
            }

            $reason = $days . '-Day Streak Bonus';
            $alreadyPaid->execute([$userId, $reason, $runStart]);

            if ((int)$alreadyPaid->fetchColumn() === 0) {
                awardPoints($userId, $bonus, 'streak_bonus', $reason);
            }
        }
    });
}

/* =============================================================
 *  DAILY CHECK-IN
 * ============================================================*/

/**
 * Check the user in for today.
 *
 * @return bool True if the check-in was new (points awarded), false if the
 *              user had already checked in today
 */
function dailyCheckIn(int $userId): bool
{
    try {
        inTransaction(function (PDO $pdo) use ($userId): void {
            // CURDATE() rather than a PHP date, so this always agrees with
            // hasCheckedInToday() even when PHP and MySQL differ on the day.
            $pdo->prepare('INSERT INTO daily_checkins (user_id, checkin_date) VALUES (?, CURDATE())')
                ->execute([$userId]);

            awardPoints($userId, POINTS_DAILY_CHECKIN, 'checkin', 'Daily Check-in');
        });
    } catch (PDOException $e) {
        // The unique key on (user_id, checkin_date) is what really stops a
        // second check-in, including two clicks racing each other.
        if (isDuplicateKeyError($e)) {
            return false;
        }
        throw $e;
    }

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
 *  PERSONAL GOALS
 * ============================================================*/

/**
 * The user's active goal and their progress toward it. Read-only.
 *
 * Progress counts points earned since the goal was set, from the kinds in
 * GOAL_PROGRESS_KINDS only. Counting from the start of the day, or counting
 * the goal bonus itself, would let a freshly saved goal start out complete.
 *
 * @return array Goal row plus points_in_period, percent and days_left,
 *               or an empty array when there is no active goal
 */
function getUserGoalProgress(int $userId): array
{
    $pdo = getPDO();

    // CURDATE() keeps this on the same clock as the dates in the table.
    $stmt = $pdo->prepare(
        'SELECT * FROM goals
         WHERE user_id = ?
           AND start_date <= CURDATE()
           AND end_date   >= CURDATE()
         ORDER BY goal_id DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    $goal = $stmt->fetch();
    if (!$goal) {
        return [];
    }

    $kinds = GOAL_PROGRESS_KINDS;
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(delta), 0)
         FROM points_transactions
         WHERE user_id = ?
           AND delta > 0
           AND kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')
           AND created_at >= ?
           AND created_at <= ?'
    );
    $stmt->execute(array_merge(
        [$userId],
        $kinds,
        [$goal['created_at'], $goal['end_date'] . ' 23:59:59']
    ));
    $earned = (int)$stmt->fetchColumn();

    $percent  = min(100, (int)round(($earned / max(1, (int)$goal['target'])) * 100));
    $daysLeft = (int)dbTodayObject()->diff(new DateTimeImmutable($goal['end_date']))->days;

    return array_merge($goal, [
        'points_in_period' => $earned,
        'percent'          => $percent,
        'days_left'        => $daysLeft,
    ]);
}

/**
 * Set a new personal goal for the user, replacing any active one.
 *
 * A goal whose bonus has already been paid cannot be replaced until its
 * period ends. Without that rule a participant could meet a small goal,
 * set another, and collect the bonus again as often as they liked.
 *
 * @return array{ok: bool, message: string}
 */
function saveGoal(int $userId, int $target, string $period): array
{
    if ($target < GOAL_TARGET_MIN || $target > GOAL_TARGET_MAX) {
        return ['ok' => false, 'message' => sprintf(
            'Goal target must be between %d and %s points.',
            GOAL_TARGET_MIN,
            number_format(GOAL_TARGET_MAX)
        )];
    }
    if (!in_array($period, ['weekly', 'monthly'], true)) {
        return ['ok' => false, 'message' => 'Please choose a valid goal period.'];
    }

    return inTransaction(function (PDO $pdo) use ($userId, $target, $period): array {
        lockUser($userId);

        $stmt = $pdo->prepare(
            'SELECT goal_id, start_date, end_date, bonus_awarded
             FROM goals
             WHERE user_id = ?
               AND start_date <= CURDATE()
               AND end_date >= CURDATE()'
        );
        $stmt->execute([$userId]);
        $active = $stmt->fetchAll();

        foreach ($active as $goal) {
            if (!empty($goal['bonus_awarded'])) {
                return ['ok' => false, 'message' => sprintf(
                    'You already hit your current goal. You can set a new one after %s.',
                    date('M j', strtotime((string)$goal['end_date']))
                )];
            }
        }

        foreach ($active as $goal) {
            // A goal set today and replaced today leaves nothing worth
            // keeping. An older one is closed off at yesterday instead.
            if ($goal['start_date'] === dbToday()) {
                $pdo->prepare('DELETE FROM goals WHERE goal_id = ?')->execute([(int)$goal['goal_id']]);
            } else {
                $pdo->prepare('UPDATE goals SET end_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE goal_id = ?')
                    ->execute([(int)$goal['goal_id']]);
            }
        }

        // dbTodayObject() so the goal window lines up with the CURDATE()
        // comparisons that decide whether a goal is currently active.
        $start = dbTodayObject();
        $end = $start->modify($period === 'monthly' ? '+29 days' : '+6 days');

        $pdo->prepare(
            'INSERT INTO goals (user_id, target, period, start_date, end_date) VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $target, $period, $start->format('Y-m-d'), $end->format('Y-m-d')]);

        return ['ok' => true, 'message' => 'Goal saved. Points you earn from now on count toward it.'];
    });
}

/**
 * Pay the goal bonus if the active goal has just been met. Write path — call
 * after something changes the user's points, never during a page render.
 */
function applyGoalCompletion(int $userId): void
{
    $goal = getUserGoalProgress($userId);

    if (!$goal || $goal['percent'] < 100 || !empty($goal['bonus_awarded'])) {
        return;
    }

    inTransaction(function (PDO $pdo) use ($userId, $goal): void {
        // User first, like saveGoal(), then re-check the goal under its own
        // lock so two concurrent requests cannot both pay out.
        lockUser($userId);
        $stmt = $pdo->prepare('SELECT bonus_awarded FROM goals WHERE goal_id = ? FOR UPDATE');
        $stmt->execute([(int)$goal['goal_id']]);

        if ((int)$stmt->fetchColumn() !== 0) {
            return;
        }

        $pdo->prepare('UPDATE goals SET bonus_awarded = 1 WHERE goal_id = ?')
            ->execute([(int)$goal['goal_id']]);

        $badge = $pdo->query("SELECT badge_id FROM badges WHERE criteria = 'goal_achieved' LIMIT 1")->fetch();
        if ($badge) {
            grantBadge($userId, (int)$badge['badge_id']);
        }

        awardPoints($userId, POINTS_GOAL_BONUS, 'goal_bonus', 'Goal Achieved Bonus', (int)$goal['goal_id']);
    });
}
