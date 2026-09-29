<?php
/**
 * EcoTrack — Challenges: which ones are open, joining, progress, completion,
 * and validating what moderators and admins enter.
 */

/**
 * SQL condition for a challenge that is genuinely open to join right now.
 *
 * status = 'active' on its own is not enough: a challenge whose end_date has
 * passed is over, whether or not anyone has closed it yet. Every list that
 * shows joinable challenges uses this, so an expired one can never be offered
 * even before closeExpiredChallenges() has run.
 *
 * @param string $alias Table alias used in the query (letters only)
 */
function liveChallengeCondition(string $alias = 'c'): string
{
    $alias = preg_replace('/[^a-z_]/i', '', $alias) ?: 'c';

    return sprintf(
        '%1$s.status = "active"
         AND (%1$s.start_date IS NULL OR %1$s.start_date <= CURDATE())
         AND (%1$s.end_date IS NULL OR %1$s.end_date >= CURDATE())',
        $alias
    );
}

/**
 * Close any active challenge whose end date has passed.
 *
 * A maintenance sweep, not a reward: it moves no points and touches no user
 * row, so it is safe to run whenever an admin or moderator acts on the
 * challenge pages. Display never depends on it having run — see
 * liveChallengeCondition().
 *
 * @return int Number of challenges closed
 */
function closeExpiredChallenges(): int
{
    return (int)getPDO()->exec(
        'UPDATE challenges
         SET status = "closed"
         WHERE status = "active"
           AND end_date IS NOT NULL
           AND end_date < CURDATE()'
    );
}

/**
 * Check a challenge form. Returns the cleaned values, or an error message.
 *
 * @return array{ok: bool, message?: string, values?: array}
 */
function validateChallengeInput(array $input, bool $withStatus): array
{
    $values = [
        'title'        => trim((string)($input['title'] ?? '')),
        'description'  => trim((string)($input['description'] ?? '')),
        'cat_id'       => (int)($input['cat_id'] ?? 0),
        'difficulty'   => (string)($input['difficulty'] ?? 'easy'),
        'points'       => (int)($input['points'] ?? 10),
        'target_count' => (int)($input['target_count'] ?? 1),
        'start_date'   => trim((string)($input['start_date'] ?? '')),
        'end_date'     => trim((string)($input['end_date'] ?? '')),
        'status'       => (string)($input['status'] ?? 'active'),
    ];

    $error = null;
    if (mb_strlen($values['title']) < 3 || isTooLong($values['title'], CHALLENGE_TITLE_MAX)) {
        $error = 'Challenge title must be 3-' . CHALLENGE_TITLE_MAX . ' characters.';
    } elseif (isTooLong($values['description'], BODY_MAX)) {
        $error = 'Challenge description must be ' . BODY_MAX . ' characters or fewer.';
    } elseif (!in_array($values['difficulty'], ['easy', 'medium', 'hard'], true)) {
        $error = 'Please choose a valid difficulty.';
    } elseif ($withStatus && !in_array($values['status'], ['active', 'closed'], true)) {
        $error = 'Please choose a valid status.';
    } elseif ($values['points'] < 1 || $values['points'] > CHALLENGE_POINTS_MAX) {
        $error = 'Challenge points must be between 1 and ' . CHALLENGE_POINTS_MAX . '.';
    } elseif ($values['target_count'] < 1 || $values['target_count'] > CHALLENGE_TARGET_MAX) {
        $error = 'Approved logs required must be between 1 and ' . CHALLENGE_TARGET_MAX . '.';
    } elseif (($values['start_date'] !== '' && !isValidDate($values['start_date']))
        || ($values['end_date'] !== '' && !isValidDate($values['end_date']))) {
        $error = 'Dates must be real calendar dates.';
    } elseif ($values['start_date'] !== '' && $values['end_date'] !== '' && $values['start_date'] > $values['end_date']) {
        $error = 'End date must be on or after the start date.';
    } elseif ($values['cat_id'] > 0 && !categoryExists($values['cat_id'])) {
        $error = 'Please choose a valid category.';
    }

    return $error === null ? ['ok' => true, 'values' => $values] : ['ok' => false, 'message' => $error];
}

/**
 * Join a challenge that is open right now.
 *
 * @return array{ok: bool, message: string}
 */
function joinChallenge(int $userId, int $challengeId): array
{
    $pdo = getPDO();

    // Refuse to join something that has already ended, even if the button
    // was rendered before it expired or the id was posted by hand.
    $live = $pdo->prepare(
        'SELECT COUNT(*) FROM challenges c WHERE c.challenge_id = ? AND ' . liveChallengeCondition('c')
    );
    $live->execute([$challengeId]);

    if ((int)$live->fetchColumn() === 0) {
        return ['ok' => false, 'message' => 'That challenge is no longer open to join.'];
    }

    try {
        $pdo->prepare('INSERT INTO challenge_participants (challenge_id, user_id) VALUES (?, ?)')
            ->execute([$challengeId, $userId]);
    } catch (PDOException $e) {
        if (isDuplicateKeyError($e)) {
            return ['ok' => false, 'message' => 'You have already joined this challenge.'];
        }
        throw $e;
    }

    return ['ok' => true, 'message' => 'Challenge joined. Submit a matching activity for moderator review to complete it.'];
}

/**
 * How many approved logs a user has that count toward a challenge.
 * Read-only, so pages can show "3 of 5 logged".
 */
function countChallengeProgress(int $userId, array $challenge): int
{
    $sql = 'SELECT COUNT(*)
            FROM activity_logs al
            WHERE al.user_id = ?
              AND al.status = "approved"
              AND al.created_at >= ?';
    $params = [$userId, $challenge['joined_at']];

    if (!empty($challenge['cat_id'])) {
        $sql .= ' AND al.cat_id = ?';
        $params[] = (int)$challenge['cat_id'];
    }
    if (!empty($challenge['start_date'])) {
        $sql .= ' AND DATE(al.created_at) >= ?';
        $params[] = $challenge['start_date'];
    }
    if (!empty($challenge['end_date'])) {
        $sql .= ' AND DATE(al.created_at) <= ?';
        $params[] = $challenge['end_date'];
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
         WHERE cp.user_id = ?'
    );
    $stmt->execute([$userId]);

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
    $stmt = getPDO()->prepare(
        'SELECT cp.id, cp.challenge_id, cp.joined_at, cp.completed,
                c.title, c.points, c.cat_id, c.start_date, c.end_date,
                c.status, c.target_count
         FROM challenge_participants cp
         JOIN challenges c ON c.challenge_id = cp.challenge_id
         WHERE cp.user_id = ?
           AND cp.completed = 0
           AND c.status IN ("active", "closed")'
    );
    $stmt->execute([$userId]);

    foreach ($stmt->fetchAll() as $row) {
        $target = max(1, (int)($row['target_count'] ?? 1));

        if (countChallengeProgress($userId, $row) < $target) {
            continue;
        }

        inTransaction(function (PDO $pdo) use ($userId, $row): void {
            $lock = $pdo->prepare('SELECT completed FROM challenge_participants WHERE id = ? FOR UPDATE');
            $lock->execute([(int)$row['id']]);

            if ((int)$lock->fetchColumn() !== 0) {
                return;
            }

            $pdo->prepare(
                'UPDATE challenge_participants SET completed = 1, completed_at = NOW() WHERE id = ?'
            )->execute([(int)$row['id']]);

            awardPoints(
                $userId,
                (int)$row['points'],
                'challenge',
                'Challenge completed: ' . $row['title'],
                (int)$row['challenge_id']
            );
        });
    }
}

/**
 * Quick participant challenge stats for dashboard/profile widgets.
 *
 * @return array{joined: int, completed: int}
 */
function getUserChallengeStats(int $userId): array
{
    $stmt = getPDO()->prepare(
        'SELECT COUNT(*) AS joined,
                COALESCE(SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END), 0) AS completed
         FROM challenge_participants
         WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];

    return ['joined' => (int)($row['joined'] ?? 0), 'completed' => (int)($row['completed'] ?? 0)];
}
