<?php
/**
 * The badge engine, criteria parsing, back-filling and manual awards.
 */
final class BadgeTest extends TestCase
{
    private function hasBadge(int $userId, string $name): bool
    {
        return (int)(array_column(getUserBadges($userId), 'earned', 'name')[$name] ?? 0) === 1;
    }

    public function testCriteriaValidation(): void
    {
        foreach (['', 'goal_achieved', 'points>=10', 'streak>=7', 'logs>=1'] as $criteria) {
            $this->assertTrue(isValidBadgeCriteria($criteria), $criteria);
        }
        foreach (['points>10', 'points >= 10', 'karma>=1', 'logs>=-1', 'goal'] as $criteria) {
            $this->assertFalse(isValidBadgeCriteria($criteria), $criteria);
        }
    }

    public function testThresholdBadgesAreAwardedAsPointsArrive(): void
    {
        $uid = Fixtures::user('climber');
        awardPoints($uid, 49, 'activity', 'Activity approved');
        $this->assertFalse($this->hasBadge($uid, 'Green Starter'));

        awardPoints($uid, 1, 'activity', 'Activity approved');
        $this->assertTrue($this->hasBadge($uid, 'Green Starter'));
    }

    /**
     * Regression: badges were checked inside awardPoints(), before the streak
     * was recalculated, so a streak badge that is not also a bonus milestone
     * waited for some later points change.
     */
    public function testStreakBadgeIsAwardedOnTheDayTheStreakReachesIt(): void
    {
        getPDO()->exec("INSERT INTO badges (name, criteria) VALUES ('Five in a Row', 'streak>=5')");

        $uid = Fixtures::user('fiver');
        for ($day = 4; $day >= 1; $day--) {
            Fixtures::checkin($uid, $day);
        }
        // This run's 3-day bonus is already paid, so today pays no bonus and
        // nothing else calls awardPoints() after the streak reaches 5.
        awardPoints($uid, STREAK_BONUSES[3], 'streak_bonus', '3-Day Streak Bonus');
        recalculateStreak($uid);

        $this->assertTrue(dailyCheckIn($uid));
        $this->assertSame(5, (int)getUserById($uid)['streak']);
        $this->assertTrue($this->hasBadge($uid, 'Five in a Row'));
    }

    public function testNewRuleIsBackfilledForPeopleWhoAlreadyQualify(): void
    {
        $rich = Fixtures::user('rich', 'participant', 300);
        $poor = Fixtures::user('poor', 'participant', 10);

        getPDO()->exec("INSERT INTO badges (name, criteria) VALUES ('Big Saver', 'points>=250')");
        $badgeId = (int)getPDO()->lastInsertId();

        $this->assertSame(1, awardBadgeToQualifiedUsers($badgeId));
        $this->assertTrue($this->hasBadge($rich, 'Big Saver'));
        $this->assertFalse($this->hasBadge($poor, 'Big Saver'));
    }

    public function testManualAwardAndRevoke(): void
    {
        $uid = Fixtures::user('helper');
        getPDO()->exec("INSERT INTO badges (name, criteria) VALUES ('Volunteer', NULL)");
        $badgeId = (int)getPDO()->lastInsertId();

        $this->assertTrue(grantBadge($uid, $badgeId));
        $this->assertFalse(grantBadge($uid, $badgeId), 'already held');
        $this->assertTrue($this->hasBadge($uid, 'Volunteer'));

        $this->assertTrue(revokeBadge($uid, $badgeId));
        $this->assertFalse($this->hasBadge($uid, 'Volunteer'));
    }

    /**
     * Regression: taking back an automatic badge from someone who still
     * qualified looked successful, then the next points change re-awarded it.
     */
    public function testAutomaticBadgeIsNotTakenBackWhileStillEarned(): void
    {
        $uid = Fixtures::user('earned_it', 'participant', 80);
        $greenStarter = (int)getPDO()->query("SELECT badge_id FROM badges WHERE criteria = 'points>=50'")->fetchColumn();
        $this->assertTrue($this->hasBadge($uid, 'Green Starter'));

        $result = takeBackBadge($uid, $greenStarter);
        $this->assertFalse($result['ok']);
        $this->assertContains('still qualifies', $result['message']);
        $this->assertTrue($this->hasBadge($uid, 'Green Starter'));

        // Given by mistake to someone below the threshold: taking it back sticks.
        $below = Fixtures::user('mistake', 'participant', 10);
        grantBadge($below, $greenStarter);
        $this->assertTrue(takeBackBadge($below, $greenStarter)['ok']);
        awardPoints($below, 5, 'checkin', 'Daily Check-in');
        $this->assertFalse($this->hasBadge($below, 'Green Starter'));
    }

    public function testCriteriaDescriptions(): void
    {
        $this->assertSame('Reach 100 points', describeBadgeCriteria('points>=100'));
        $this->assertSame('Get 1 activity approved', describeBadgeCriteria('logs>=1'));
        $this->assertSame('Awarded by an administrator', describeBadgeCriteria(null));
    }
}
