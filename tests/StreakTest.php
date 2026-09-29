<?php
/**
 * Streaks and their milestone bonuses.
 */
final class StreakTest extends TestCase
{
    public function testConsecutiveDaysBuildAStreak(): void
    {
        $uid = Fixtures::user('steady');
        Fixtures::checkin($uid, 2);
        Fixtures::checkin($uid, 1);
        Fixtures::checkin($uid, 0);

        $this->assertSame(3, recalculateStreak($uid)['streak']);
    }

    public function testAGapBreaksTheStreak(): void
    {
        $uid = Fixtures::user('gappy');
        Fixtures::checkin($uid, 5);
        Fixtures::checkin($uid, 4);
        Fixtures::checkin($uid, 2);

        $this->assertSame(0, recalculateStreak($uid)['streak']);
    }

    /**
     * Regression: bonuses used to be paid only when the streak landed exactly
     * on a milestone. A late approval that filled a gap could move a streak
     * from 1 straight to 4, and the 3-day bonus was never paid.
     */
    public function testLateApprovalThatJumpsPastAMilestoneStillPaysIt(): void
    {
        $uid = Fixtures::user('jumper');
        Fixtures::checkin($uid, 3);
        Fixtures::checkin($uid, 2);
        Fixtures::checkin($uid, 0);
        applyStreakBonuses($uid);
        $this->assertSame(0, Fixtures::ledgerKind($uid, 'streak_bonus'), 'no bonus while the run is broken');

        // Yesterday's activity is approved late, joining the two runs into four days.
        $logId = Fixtures::pendingLog($uid, 1);
        reviewSubmission(Fixtures::moderatorId(), false, $logId, 'approve', '');

        $this->assertSame(4, (int)getUserById($uid)['streak']);
        $this->assertSame(STREAK_BONUSES[3], Fixtures::ledgerKind($uid, 'streak_bonus'));
    }

    public function testMilestoneIsPaidOncePerRun(): void
    {
        $uid = Fixtures::user('once');
        Fixtures::checkin($uid, 2);
        Fixtures::checkin($uid, 1);
        Fixtures::checkin($uid, 0);

        applyStreakBonuses($uid);
        applyStreakBonuses($uid);

        $this->assertSame(STREAK_BONUSES[3], Fixtures::ledgerKind($uid, 'streak_bonus'));
    }

    public function testANewRunEarnsTheMilestoneAgain(): void
    {
        $uid = Fixtures::user('comeback');

        // An earlier run, paid at the time it happened.
        awardPoints($uid, STREAK_BONUSES[3], 'streak_bonus', '3-Day Streak Bonus');
        getPDO()->prepare('UPDATE points_transactions SET created_at = DATE_SUB(NOW(), INTERVAL 10 DAY) WHERE user_id = ?')
            ->execute([$uid]);

        Fixtures::checkin($uid, 2);
        Fixtures::checkin($uid, 1);
        Fixtures::checkin($uid, 0);
        applyStreakBonuses($uid);

        $this->assertSame(2 * STREAK_BONUSES[3], Fixtures::ledgerKind($uid, 'streak_bonus'));
    }

    public function testSevenDayRunPaysBothMilestones(): void
    {
        $uid = Fixtures::user('week');
        for ($day = 6; $day >= 0; $day--) {
            Fixtures::checkin($uid, $day);
        }
        applyStreakBonuses($uid);

        $this->assertSame(STREAK_BONUSES[3] + STREAK_BONUSES[7], Fixtures::ledgerKind($uid, 'streak_bonus'));
        $badges = array_column(getUserBadges($uid), 'earned', 'name');
        $this->assertSame(1, (int)$badges['7-Day Streak']);
    }
}
