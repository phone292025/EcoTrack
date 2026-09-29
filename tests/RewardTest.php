<?php
/**
 * The Green Shop, redemption fulfilment and the reward catalogue.
 */
final class RewardTest extends TestCase
{
    /** Seeded "Campus Cafe Voucher": 60 points, 100 in stock. */
    private const VOUCHER = 3;

    private function stock(int $rewardId): int
    {
        $stmt = getPDO()->prepare('SELECT stock FROM rewards WHERE reward_id = ?');
        $stmt->execute([$rewardId]);
        return (int)$stmt->fetchColumn();
    }

    private function lastRedemptionId(int $userId): int
    {
        $stmt = getPDO()->prepare('SELECT MAX(redemption_id) FROM redemptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    public function testRedeemTakesPointsAndStock(): void
    {
        $uid = Fixtures::user('shopper', 'participant', 100);

        $this->assertTrue(redeemReward($uid, self::VOUCHER)['ok']);
        $this->assertSame(40, Fixtures::balance($uid));
        $this->assertSame(99, $this->stock(self::VOUCHER));
        $this->assertSame('pending', getUserRedemptions($uid)[0]['status']);
    }

    public function testCannotRedeemWithoutEnoughPointsOrStock(): void
    {
        $uid = Fixtures::user('skint', 'participant', 59);
        $this->assertFalse(redeemReward($uid, self::VOUCHER)['ok']);

        getPDO()->exec('UPDATE rewards SET stock = 0 WHERE reward_id = ' . self::VOUCHER);
        awardPoints($uid, 100, 'adjustment', 'Top-up');
        $this->assertFalse(redeemReward($uid, self::VOUCHER)['ok']);

        $this->assertSame(159, Fixtures::balance($uid));
        $this->assertSame([], getUserRedemptions($uid));
    }

    public function testCancellingRefundsAndRestocks(): void
    {
        $uid = Fixtures::user('changed_mind', 'participant', 60);
        redeemReward($uid, self::VOUCHER);
        $redemptionId = $this->lastRedemptionId($uid);

        $this->assertTrue(cancelRedemption(Fixtures::adminId(), $redemptionId)['ok']);
        $this->assertFalse(cancelRedemption(Fixtures::adminId(), $redemptionId)['ok'], 'cannot cancel twice');

        $this->assertSame(60, Fixtures::balance($uid));
        $this->assertSame(60, Fixtures::ledgerTotal($uid));
        $this->assertSame(100, $this->stock(self::VOUCHER));
        $this->assertSame('cancelled', getUserRedemptions($uid)[0]['status']);
    }

    public function testFulfilledRedemptionCannotBeCancelled(): void
    {
        $uid = Fixtures::user('collected', 'participant', 60);
        redeemReward($uid, self::VOUCHER);
        $redemptionId = $this->lastRedemptionId($uid);

        $this->assertTrue(fulfilRedemption(Fixtures::adminId(), $redemptionId)['ok']);
        $this->assertFalse(cancelRedemption(Fixtures::adminId(), $redemptionId)['ok']);
        $this->assertSame([], getPendingRedemptions());
    }

    /**
     * Regression: deleting a reward used to cascade and wipe every
     * participant's record of redeeming it.
     */
    public function testRemovingARedeemedRewardHidesItInstead(): void
    {
        $uid = Fixtures::user('historian', 'participant', 60);
        redeemReward($uid, self::VOUCHER);

        $result = removeReward(self::VOUCHER);
        $this->assertTrue($result['ok']);
        $this->assertContains('hidden', $result['message']);
        $this->assertSame(1, count(getUserRedemptions($uid)));

        // And the database refuses a direct delete too.
        try {
            getPDO()->exec('DELETE FROM rewards WHERE reward_id = ' . self::VOUCHER);
            $this->fail('The foreign key should block deleting a redeemed reward.');
        } catch (PDOException) {
        }
    }

    public function testRemovingAnUnusedRewardDeletesIt(): void
    {
        $this->assertTrue(removeReward(1)['ok']);
        $this->assertSame(0, (int)getPDO()->query('SELECT COUNT(*) FROM rewards WHERE reward_id = 1')->fetchColumn());
    }

    public function testRewardInputValidation(): void
    {
        $valid = ['name' => 'Seed kit', 'category' => 'Lifestyle', 'point_cost' => 50, 'stock' => 5];
        $this->assertTrue(validateRewardInput($valid)['ok']);
        $this->assertFalse(validateRewardInput(['category' => 'Weapons'] + $valid)['ok']);
        $this->assertFalse(validateRewardInput(['point_cost' => 0] + $valid)['ok']);
        $this->assertFalse(validateRewardInput(['stock' => -1] + $valid)['ok']);
        $this->assertFalse(validateRewardInput(['name' => str_repeat('n', REWARD_NAME_MAX + 1)] + $valid)['ok']);
    }
}
