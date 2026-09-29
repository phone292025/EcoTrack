<?php
/**
 * Submitting activity and the moderation decision.
 */
final class ModerationTest extends TestCase
{
    public function testApprovalPaysOnceAndOnlyOnce(): void
    {
        $uid = Fixtures::user('approved');
        $logId = Fixtures::pendingLog($uid);
        $mod = Fixtures::moderatorId();

        $this->assertTrue(reviewSubmission($mod, false, $logId, 'approve', '')['ok']);
        $this->assertFalse(reviewSubmission($mod, false, $logId, 'approve', '')['ok']);
        $this->assertSame(POINTS_PER_ACTIVITY, Fixtures::balance($uid));
    }

    public function testRejectionNeedsAReason(): void
    {
        $uid = Fixtures::user('rejected');
        $logId = Fixtures::pendingLog($uid);
        $mod = Fixtures::moderatorId();

        $this->assertFalse(reviewSubmission($mod, false, $logId, 'reject', '   ')['ok']);
        $this->assertTrue(reviewSubmission($mod, false, $logId, 'reject', 'Photo does not show the activity')['ok']);
        $this->assertSame(0, Fixtures::balance($uid));
    }

    public function testOnlyAdminsResolveFlaggedSubmissions(): void
    {
        $uid = Fixtures::user('flagged');
        $logId = Fixtures::pendingLog($uid);
        $mod = Fixtures::moderatorId();

        $this->assertTrue(reviewSubmission($mod, false, $logId, 'flag', 'Looks copied')['ok']);
        $this->assertFalse(reviewSubmission($mod, false, $logId, 'approve', '')['ok']);
        $this->assertFalse(reviewSubmission(Fixtures::adminId(), true, $logId, 'flag', '')['ok'], 'admins do not flag');
        $this->assertTrue(reviewSubmission(Fixtures::adminId(), true, $logId, 'approve', '')['ok']);
        $this->assertSame(POINTS_PER_ACTIVITY, Fixtures::balance($uid));
    }

    public function testSubmissionValidation(): void
    {
        $uid = Fixtures::user('submitter');

        $this->assertSame([], submitActivity($uid, 1, 'Cycled to campus instead of driving'));
        $this->assertTrue(count(submitActivity($uid, 999, 'Cycled to campus instead of driving')) > 0, 'unknown category');
        $this->assertTrue(count(submitActivity($uid, 1, 'short')) > 0, 'too short');
        $this->assertTrue(count(submitActivity($uid, 1, str_repeat('x', ACTIVITY_DESCRIPTION_MAX + 1))) > 0, 'too long');
    }

    public function testPendingSubmissionsAreCapped(): void
    {
        $uid = Fixtures::user('spammer');
        for ($i = 0; $i < MAX_PENDING_SUBMISSIONS; $i++) {
            $this->assertSame([], submitActivity($uid, 1, 'Recycled some bottles number ' . $i));
        }

        $errors = submitActivity($uid, 1, 'One more recycling trip');
        $this->assertSame(1, count($errors));
        $this->assertContains('waiting for review', $errors[0]);
    }

    public function testChallengeCompletesWhenTargetIsReached(): void
    {
        $uid = Fixtures::user('challenger');
        $pdo = getPDO();
        $pdo->exec(
            "INSERT INTO challenges (title, cat_id, points, target_count, status)
             VALUES ('Recycle twice', 1, 30, 2, 'active')"
        );
        $challengeId = (int)$pdo->lastInsertId();

        $this->assertTrue(joinChallenge($uid, $challengeId)['ok']);
        $this->assertFalse(joinChallenge($uid, $challengeId)['ok'], 'cannot join twice');

        $mod = Fixtures::moderatorId();
        reviewSubmission($mod, false, Fixtures::pendingLog($uid), 'approve', '');
        $this->assertSame(0, Fixtures::ledgerKind($uid, 'challenge'));

        reviewSubmission($mod, false, Fixtures::pendingLog($uid), 'approve', '');
        $this->assertSame(30, Fixtures::ledgerKind($uid, 'challenge'));
        $this->assertSame(['joined' => 1, 'completed' => 1], getUserChallengeStats($uid));
    }

    public function testExpiredChallengeCannotBeJoined(): void
    {
        $uid = Fixtures::user('too_late');
        getPDO()->exec(
            "INSERT INTO challenges (title, points, status, end_date)
             VALUES ('Last week', 10, 'active', DATE_SUB(CURDATE(), INTERVAL 1 DAY))"
        );

        $this->assertFalse(joinChallenge($uid, (int)getPDO()->lastInsertId())['ok']);
    }

    public function testChallengeInputValidation(): void
    {
        $valid = ['title' => 'Walk more', 'points' => 20, 'target_count' => 3, 'difficulty' => 'easy'];
        $this->assertTrue(validateChallengeInput($valid, false)['ok']);
        $this->assertFalse(validateChallengeInput(['points' => CHALLENGE_POINTS_MAX + 1] + $valid, false)['ok']);
        $this->assertFalse(validateChallengeInput(['start_date' => '2026-02-30'] + $valid, false)['ok']);
        $this->assertFalse(validateChallengeInput(['start_date' => '2026-05-02', 'end_date' => '2026-05-01'] + $valid, false)['ok']);
        $this->assertFalse(validateChallengeInput(['cat_id' => 999] + $valid, false)['ok']);
        $this->assertFalse(validateChallengeInput(['title' => str_repeat('a', CHALLENGE_TITLE_MAX + 1)] + $valid, false)['ok']);
    }
}
