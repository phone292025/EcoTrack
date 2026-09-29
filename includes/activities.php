<?php
/**
 * EcoTrack — Activity logs: categories, submitting an activity, and the
 * moderation decision that turns a submission into points.
 */

/**
 * @return array<int, array{cat_id: int, name: string}>
 */
function getCategories(): array
{
    return getPDO()->query('SELECT cat_id, name FROM categories ORDER BY cat_id')->fetchAll();
}

function categoryExists(int $catId): bool
{
    $stmt = getPDO()->prepare('SELECT COUNT(*) FROM categories WHERE cat_id = ?');
    $stmt->execute([$catId]);

    return (int)$stmt->fetchColumn() > 0;
}

/**
 * The joined challenge a participant is logging activity for, or null.
 */
function getJoinedChallenge(int $userId, int $challengeId): ?array
{
    if ($challengeId <= 0) {
        return null;
    }

    $stmt = getPDO()->prepare(
        'SELECT c.challenge_id, c.title, c.description, c.cat_id, c.start_date, c.end_date,
                cat.name AS cat_name, cp.completed
         FROM challenge_participants cp
         JOIN challenges c ON c.challenge_id = cp.challenge_id
         LEFT JOIN categories cat ON cat.cat_id = c.cat_id
         WHERE cp.challenge_id = ? AND cp.user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$challengeId, $userId]);

    return $stmt->fetch() ?: null;
}

/**
 * Validate and store a new activity log, pending moderator review.
 *
 * The text is checked before the evidence file is touched, so a rejected
 * submission never leaves an orphaned upload behind.
 *
 * @param array|null $upload    The $_FILES entry for the evidence image, if any
 * @param array|null $challenge The joined challenge being logged for, if any
 * @return string[] Problems to show the participant; empty on success
 */
function submitActivity(int $userId, int $catId, string $description, ?array $upload = null, ?array $challenge = null): array
{
    $errors = [];
    $description = trim($description);

    if ($catId <= 0 || !categoryExists($catId)) {
        $errors[] = 'Choose a category.';
    }
    if (mb_strlen($description) < ACTIVITY_DESCRIPTION_MIN) {
        $errors[] = 'Description must be at least ' . ACTIVITY_DESCRIPTION_MIN . ' characters.';
    } elseif (isTooLong($description, ACTIVITY_DESCRIPTION_MAX)) {
        $errors[] = 'Description must be ' . ACTIVITY_DESCRIPTION_MAX . ' characters or fewer.';
    }
    if ($challenge && !empty($challenge['cat_id']) && $catId !== (int)$challenge['cat_id']) {
        $errors[] = 'This challenge must be submitted under the "' . $challenge['cat_name'] . '" category.';
    }
    if (countPendingSubmissions($userId) >= MAX_PENDING_SUBMISSIONS) {
        $errors[] = 'You already have ' . MAX_PENDING_SUBMISSIONS
            . ' activities waiting for review. Please wait for a moderator to get to them.';
    }

    if ($errors) {
        return $errors;
    }

    $evidence = null;
    if ($upload !== null && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $evidence = handleFileUpload($upload, 'evidence');
        if ($evidence === null) {
            return ['Evidence image could not be uploaded. Use a JPG, PNG, GIF or WebP under 5 MB.'];
        }
    }

    getPDO()->prepare(
        'INSERT INTO activity_logs (user_id, cat_id, description, evidence, points, status)
         VALUES (?, ?, ?, ?, ?, "pending")'
    )->execute([$userId, $catId, $description, $evidence, POINTS_PER_ACTIVITY]);

    return [];
}

function countPendingSubmissions(int $userId): int
{
    $stmt = getPDO()->prepare('SELECT COUNT(*) FROM activity_logs WHERE user_id = ? AND status = "pending"');
    $stmt->execute([$userId]);

    return (int)$stmt->fetchColumn();
}

/**
 * Approve, reject or flag a submission.
 *
 * Moderators work the pending queue and can flag anything doubtful for an
 * admin. Only admins resolve flagged submissions. Points, streaks, goals and
 * challenge completions are all settled here, in the request that actually
 * changes the submission's status.
 *
 * @param string $action 'approve', 'reject' or 'flag'
 * @return array{ok: bool, message: string}
 */
function reviewSubmission(int $reviewerId, bool $isAdmin, int $logId, string $action, string $note): array
{
    $note = trim($note);

    if ($logId <= 0) {
        return ['ok' => false, 'message' => 'Invalid submission selected.'];
    }
    if (!in_array($action, ['approve', 'reject', 'flag'], true) || ($isAdmin && $action === 'flag')) {
        return ['ok' => false, 'message' => 'Invalid moderation action.'];
    }
    if (isTooLong($note, REVIEW_NOTE_MAX)) {
        return ['ok' => false, 'message' => 'Keep the note to ' . REVIEW_NOTE_MAX . ' characters or fewer.'];
    }
    // A rejection without a reason leaves the participant nothing to act on.
    if ($action === 'reject' && $note === '') {
        return ['ok' => false, 'message' => 'Add a short reason when rejecting, so the participant knows what to fix.'];
    }

    // Find the owner without a lock, so the locks below can follow the
    // user-first order described at lockUser().
    $owner = getPDO()->prepare('SELECT user_id FROM activity_logs WHERE log_id = ?');
    $owner->execute([$logId]);
    $ownerId = (int)$owner->fetchColumn();

    $result = inTransaction(function (PDO $pdo) use ($reviewerId, $isAdmin, $logId, $action, $note, $ownerId): array {
        if ($ownerId > 0) {
            lockUser($ownerId);
        }
        $stmt = $pdo->prepare('SELECT * FROM activity_logs WHERE log_id = ? FOR UPDATE');
        $stmt->execute([$logId]);
        $log = $stmt->fetch();

        if (!$log) {
            return ['ok' => false, 'message' => 'Submission not found.'];
        }
        if ($action === 'flag' && $log['status'] !== 'pending') {
            return ['ok' => false, 'message' => 'Only pending submissions can be flagged.'];
        }
        if ($action !== 'flag' && !in_array($log['status'], ['pending', 'flagged'], true)) {
            return ['ok' => false, 'message' => 'This submission has already been resolved.'];
        }
        if ($log['status'] === 'flagged' && !$isAdmin) {
            return ['ok' => false, 'message' => 'Only admins can resolve flagged submissions.'];
        }

        if ($action === 'flag') {
            $pdo->prepare(
                'UPDATE activity_logs
                 SET status = "flagged", review_note = NULLIF(?, ""),
                     flagged_by = ?, reviewed_by = NULL, reviewed_at = NULL
                 WHERE log_id = ?'
            )->execute([$note, $reviewerId, $logId]);

            return ['ok' => true, 'message' => 'Submission flagged for admin review.'];
        }

        $pdo->prepare(
            'UPDATE activity_logs
             SET status = ?, review_note = NULLIF(?, ""), reviewed_by = ?, reviewed_at = NOW()
             WHERE log_id = ?'
        )->execute([$action === 'approve' ? 'approved' : 'rejected', $note, $reviewerId, $logId]);

        if ($action === 'reject') {
            return ['ok' => true, 'message' => 'Submission rejected and the reason was sent to the participant.'];
        }

        awardPoints((int)$log['user_id'], (int)$log['points'], 'activity', 'Activity approved', $logId);

        return ['ok' => true, 'message' => 'Submission approved.', 'user_id' => (int)$log['user_id']];
    });

    // Streaks, goals and challenge completions follow the approval and take
    // their own locks.
    if (isset($result['user_id'])) {
        applyStreakBonuses($result['user_id']);
        refreshUserChallengeProgress($result['user_id']);
        applyGoalCompletion($result['user_id']);
        unset($result['user_id']);
    }

    return $result;
}

/**
 * A page of a user's activity history, newest first.
 */
function getUserActivityLog(int $userId, int $page = 1, int $perPage = 10): array
{
    $offset = max(0, ($page - 1) * $perPage);
    $stmt   = getPDO()->prepare(
        'SELECT al.log_id, c.name AS cat_name, al.description,
                al.points, al.status, al.evidence, al.review_note, al.created_at
         FROM activity_logs al
         JOIN categories c ON c.cat_id = al.cat_id
         WHERE al.user_id = ?
         ORDER BY al.created_at DESC, al.log_id DESC
         LIMIT ? OFFSET ?'
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
