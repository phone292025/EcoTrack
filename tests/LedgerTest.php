<?php
/**
 * The points ledger: users.points must always equal the sum of the ledger,
 * and a balance can never go below zero.
 */
final class LedgerTest extends TestCase
{
    public function testAwardAndDeductKeepBalanceEqualToLedger(): void
    {
        $uid = Fixtures::user('ledger');

        $this->assertTrue(awardPoints($uid, 40, 'activity', 'Activity approved'));
        $this->assertTrue(awardPoints($uid, -15, 'redemption', 'Redeemed: thing'));

        $this->assertSame(25, Fixtures::balance($uid));
        $this->assertSame(25, Fixtures::ledgerTotal($uid));
    }

    public function testDeductionBelowZeroIsRefusedAndWritesNothing(): void
    {
        $uid = Fixtures::user('broke', 'participant', 10);

        $this->assertFalse(awardPoints($uid, -11, 'redemption', 'Redeemed: too much'));
        $this->assertSame(10, Fixtures::balance($uid));
        $this->assertSame(10, Fixtures::ledgerTotal($uid));
    }

    public function testUnknownKindIsRejected(): void
    {
        $uid = Fixtures::user('kinds');

        try {
            awardPoints($uid, 5, 'free_money', 'Nope');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, Fixtures::ledgerTotal($uid));
            return;
        }

        $this->fail('An unknown ledger kind should throw.');
    }

    public function testDailyCheckInPaysOncePerDay(): void
    {
        $uid = Fixtures::user('checker');

        $this->assertTrue(dailyCheckIn($uid));
        $this->assertFalse(dailyCheckIn($uid));
        $this->assertSame(POINTS_DAILY_CHECKIN, Fixtures::balance($uid));
        $this->assertTrue(hasCheckedInToday($uid));
    }

    public function testPointsTotalsTreatRefundsAsLessSpentNotMoreEarned(): void
    {
        $uid = Fixtures::user('totals');
        awardPoints($uid, 100, 'activity', 'Activity approved');
        awardPoints($uid, -60, 'redemption', 'Redeemed: bottle');
        awardPoints($uid, 60, 'refund', 'Refund: bottle');

        $this->assertSame(['earned' => 100, 'spent' => 0], getPointsTotals($uid));
    }

    public function testLeaderboardSharesRanksOnTies(): void
    {
        foreach (['ana' => 50, 'ben' => 50, 'cai' => 20] as $name => $points) {
            Fixtures::user($name, 'participant', $points);
        }

        $ranks = array_column(getLeaderboard(10), 'rank', 'username');
        $this->assertSame(['ana' => 1, 'ben' => 1, 'cai' => 3], $ranks);
    }
}
