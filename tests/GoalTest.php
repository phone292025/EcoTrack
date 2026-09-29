<?php
/**
 * Personal goals. The headline regression: re-saving a small goal after
 * each approval used to pay the 25-point bonus every time, because the new
 * goal counted everything earned that day, including the last bonus.
 */
final class GoalTest extends TestCase
{
    public function testResavingGoalsCannotFarmTheBonus(): void
    {
        $uid = Fixtures::user('farmer');
        $moderator = Fixtures::moderatorId();

        $this->assertTrue(saveGoal($uid, GOAL_TARGET_MIN, 'weekly')['ok']);

        for ($i = 0; $i < 3; $i++) {
            $logId = Fixtures::pendingLog($uid);
            $this->assertTrue(reviewSubmission($moderator, false, $logId, 'approve', '')['ok']);
            saveGoal($uid, GOAL_TARGET_MIN, 'weekly');
        }

        $this->assertSame(3 * POINTS_PER_ACTIVITY + POINTS_GOAL_BONUS, Fixtures::balance($uid));
        $this->assertSame(POINTS_GOAL_BONUS, Fixtures::ledgerKind($uid, 'goal_bonus'));
    }

    public function testAchievedGoalCannotBeReplacedUntilItEnds(): void
    {
        $uid = Fixtures::user('achiever');
        saveGoal($uid, 10, 'weekly');
        awardPoints($uid, 10, 'activity', 'Activity approved');
        applyGoalCompletion($uid);

        $result = saveGoal($uid, 10, 'weekly');
        $this->assertFalse($result['ok']);
        $this->assertContains('already hit', $result['message']);
    }

    public function testProgressOnlyCountsPointsEarnedAfterTheGoalWasSet(): void
    {
        $uid = Fixtures::user('late_setter');
        awardPoints($uid, 50, 'activity', 'Activity approved');
        Fixtures::ageEverything($uid, 5);

        saveGoal($uid, 20, 'weekly');
        $this->assertSame(0, getUserGoalProgress($uid)['points_in_period']);

        awardPoints($uid, 10, 'activity', 'Activity approved');
        $this->assertSame(10, getUserGoalProgress($uid)['points_in_period']);
        $this->assertSame(50, getUserGoalProgress($uid)['percent']);
    }

    public function testBonusesAndRefundsDoNotCountTowardAGoal(): void
    {
        $uid = Fixtures::user('refunded');
        saveGoal($uid, 100, 'weekly');

        awardPoints($uid, 30, 'refund', 'Refund: bag');
        awardPoints($uid, 25, 'goal_bonus', 'Goal Achieved Bonus');
        awardPoints($uid, 5, 'checkin', 'Daily Check-in');

        $this->assertSame(5, getUserGoalProgress($uid)['points_in_period']);
    }

    public function testBonusIsPaidOnceWithTheGoalBadge(): void
    {
        $uid = Fixtures::user('crusher');
        saveGoal($uid, 10, 'weekly');
        awardPoints($uid, 10, 'activity', 'Activity approved');

        applyGoalCompletion($uid);
        applyGoalCompletion($uid);

        $this->assertSame(POINTS_GOAL_BONUS, Fixtures::ledgerKind($uid, 'goal_bonus'));
        $badges = array_column(getUserBadges($uid), 'earned', 'name');
        $this->assertSame(1, (int)$badges['Goal Crusher']);
    }

    public function testGoalTargetLimitsAreEnforced(): void
    {
        $uid = Fixtures::user('limits');
        $this->assertFalse(saveGoal($uid, GOAL_TARGET_MIN - 1, 'weekly')['ok']);
        $this->assertFalse(saveGoal($uid, GOAL_TARGET_MAX + 1, 'weekly')['ok']);
        $this->assertFalse(saveGoal($uid, 50, 'yearly')['ok']);
        $this->assertTrue(saveGoal($uid, 50, 'monthly')['ok']);
    }
}
