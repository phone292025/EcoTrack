<?php
/**
 * Session checks that are easier to drive in-process than over HTTP.
 */
final class SessionTest extends TestCase
{
    private function signIn(int $userId): void
    {
        $stmt = getPDO()->prepare('SELECT * FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $_SESSION = [
            'user_id'   => $userId,
            'username'  => $user['username'],
            'role'      => $user['role'],
            'points'    => 0,
            'auth_fp'   => sessionFingerprint($user['password']),
            'last_seen' => time(),
        ];
    }

    public function testActiveSessionIsRefreshedFromTheDatabase(): void
    {
        $uid = Fixtures::user('fresh', 'participant', 42);
        $this->signIn($uid);

        syncSessionUser();

        $this->assertTrue(isLoggedIn());
        $this->assertSame(42, currentPoints());
    }

    public function testIdleSessionExpires(): void
    {
        $uid = Fixtures::user('sleepy');
        $this->signIn($uid);
        $_SESSION['last_seen'] = time() - (SESSION_IDLE_MINUTES * 60) - 1;

        syncSessionUser();

        $this->assertFalse(isLoggedIn());
        $this->assertContains('inactivity', implode(' ', takeFlash()['error']));
    }

    public function testRoleChangeIsPickedUp(): void
    {
        $uid = Fixtures::user('promoted');
        $this->signIn($uid);
        getPDO()->prepare('UPDATE users SET role = "moderator" WHERE user_id = ?')->execute([$uid]);

        syncSessionUser();

        $this->assertSame('moderator', currentRole());
    }

    public function testLoginThrottleKeyIgnoresCaseAndIdentifierType(): void
    {
        $user = ['user_id' => 7];
        $this->assertSame('user:7', loginThrottleKey('Someone', $user));
        $this->assertSame('user:7', loginThrottleKey('someone@example.test', $user));
        $this->assertSame('name:ghost', loginThrottleKey('  GHOST ', null));
    }
}
