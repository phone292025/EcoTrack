<?php
/**
 * Security behaviour that only shows up over HTTP: sessions, throttling,
 * headers, and which files the web server will hand out.
 */
final class HttpSecurityTest extends HttpTestCase
{
    private function wrongPassword(string $identifier): HttpResponse
    {
        $client = $this->client();
        $client->login($identifier, 'WrongPassword1');

        return $client->get('/login.php');
    }

    public function testFiveWrongPasswordsLockTheAccount(): void
    {
        $this->participant('victim');

        for ($i = 1; $i <= LOGIN_MAX_ATTEMPTS_PER_ACCOUNT; $i++) {
            $this->assertNotContains('Too many failed attempts', $this->wrongPassword('victim')->body, 'attempt ' . $i);
        }
        $this->assertContains('Too many failed attempts', $this->wrongPassword('victim')->body);

        // The right password is refused too while the lock holds.
        $response = $this->client()->login('victim', 'Password1');
        $this->assertSame('/login.php', $response->location());
    }

    /**
     * Regression: a successful login used to clear every failure recorded
     * for the IP address, so logging into your own account between guesses
     * reset the counter on someone else's.
     */
    public function testLoggingIntoYourOwnAccountDoesNotResetSomeoneElsesLock(): void
    {
        $this->participant('victim');
        $this->participant('attacker');

        for ($round = 0; $round < 2; $round++) {
            for ($i = 0; $i < 3; $i++) {
                $this->wrongPassword('victim');
            }
            $this->loggedIn('attacker', 'Password1');
        }

        $this->assertContains('Too many failed attempts', $this->wrongPassword('victim')->body);
    }

    public function testUsernameAndEmailShareOneLimit(): void
    {
        $this->participant('victim');

        for ($i = 0; $i < 3; $i++) {
            $this->wrongPassword('victim');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->wrongPassword('victim@example.test');
        }

        $this->assertContains('Too many failed attempts', $this->wrongPassword('VICTIM')->body);
    }

    /**
     * Regression: the role was read from the session only, so a demoted or
     * deleted user kept their old access until they logged out.
     */
    public function testDemotionTakesEffectImmediately(): void
    {
        getPDO()->exec("INSERT INTO users (username, email, password, role) VALUES ('mod2', 'mod2@example.test', '"
            . password_hash('Password1', PASSWORD_DEFAULT) . "', 'moderator')");
        $mod2 = $this->loggedIn('mod2', 'Password1');
        $this->assertSame(200, $mod2->get('/moderator/review_submissions.php')->status);

        getPDO()->exec("UPDATE users SET role = 'participant' WHERE username = 'mod2'");
        $this->assertSame(403, $mod2->get('/moderator/review_submissions.php')->status);
        $this->assertSame(200, $mod2->get('/participant/dashboard.php')->status);
    }

    public function testDeletedUserIsLoggedOut(): void
    {
        $client = $this->participant('leaver');
        getPDO()->exec("DELETE FROM users WHERE username = 'leaver'");

        $response = $client->get('/participant/dashboard.php');
        $this->assertSame(302, $response->status);
        $this->assertSame('/login.php', $response->location());
    }

    public function testChangingPasswordLogsOutOtherSessions(): void
    {
        $laptop = $this->participant('two_devices');
        $phone = $this->loggedIn('two_devices', 'Password1');

        $laptop->submit('/participant/profile.php', [
            'action' => 'change_password',
            'current_password' => 'Password1',
            'new_password' => 'Different2',
            'confirm_password' => 'Different2',
        ]);

        $this->assertSame(200, $laptop->get('/participant/profile.php')->status, 'the session that changed it stays in');
        $this->assertSame('/login.php', $phone->get('/participant/profile.php')->location(), 'the other session is out');
    }

    public function testLogoutNeedsAPostWithAToken(): void
    {
        $client = $this->participant('leaving');

        // A GET only shows a confirmation, so a link or <img> cannot log you out.
        $this->assertPageOk($client->get('/logout.php'), 'logout confirmation');
        $this->assertSame(200, $client->get('/participant/dashboard.php')->status);

        $this->assertSame(403, $client->post('/logout.php', ['csrf' => 'forged'])->status);
        $this->assertSame(200, $client->get('/participant/dashboard.php')->status);

        $this->assertSame('/login.php', $client->submit('/participant/dashboard.php', [], '/logout.php')->location());
        $this->assertSame('/login.php', $client->get('/participant/dashboard.php')->location());
    }

    public function testSecurityHeadersAreSent(): void
    {
        $response = $this->client()->get('/login.php');

        $this->assertContains("script-src 'self'", $response->header('content-security-policy'));
        $this->assertContains("frame-ancestors 'none'", $response->header('content-security-policy'));
        $this->assertSame('DENY', $response->header('x-frame-options'));
        $this->assertSame('nosniff', $response->header('x-content-type-options'));
    }

    public function testNoPageUsesInlineScript(): void
    {
        $client = $this->admin();
        foreach (['/admin/dashboard.php', '/admin/rewards_management.php', '/admin/badges_management.php'] as $path) {
            $this->assertNotMatches('/<script(?![^>]*(\ssrc=|type="application\/json"))[^>]*>/', $client->get($path)->body, $path);
            $this->assertNotContains('onclick=', $client->get($path)->body, $path);
        }

        $participant = $this->participant();
        foreach (['/participant/dashboard.php', '/participant/profile.php', '/participant/points.php'] as $path) {
            $this->assertNotMatches('/<script(?![^>]*(\ssrc=|type="application\/json"))[^>]*>/', $participant->get($path)->body, $path);
        }
    }

    public function testInternalFilesAreNotServed(): void
    {
        $client = $this->client();
        foreach ([
            '/database/ecotrack.sql',
            '/database/db.php',
            '/includes/functions.php',
            '/scripts/migrate.php',
            '/tests/run.php',
            '/README.md',
            '/.gitignore',
            '/router.php',
        ] as $path) {
            $this->assertSame(404, $client->get($path)->status, $path);
        }
    }

    public function testEvidenceIsOnlyShownToTheOwnerAndStaff(): void
    {
        $name = bin2hex(random_bytes(16)) . '.png';
        $path = UPLOAD_DIR . '/evidence/' . $name;
        $image = imagecreatetruecolor(4, 4);
        imagepng($image, $path);

        try {
            $owner = $this->participant('owner');
            $ownerId = Fixtures::userId('owner');
            getPDO()->prepare(
                'INSERT INTO activity_logs (user_id, cat_id, description, evidence, points) VALUES (?, 1, "Photo test entry", ?, 10)'
            )->execute([$ownerId, $name]);

            $url = '/evidence.php?file=' . $name;
            $this->assertSame(404, $this->client()->get('/uploads/evidence/' . $name)->status, 'direct path');
            $this->assertSame('/login.php', $this->client()->get($url)->location(), 'guest');
            $this->assertSame(404, $this->participant('stranger')->get($url)->status, 'other participant');

            $response = $owner->get($url);
            $this->assertSame(200, $response->status, 'owner');
            $this->assertSame('image/png', $response->header('content-type'));
            $this->assertSame(200, $this->moderator()->get($url)->status, 'moderator');
            $this->assertSame(404, $owner->get('/evidence.php?file=../../database/db.php')->status, 'path traversal');
        } finally {
            @unlink($path);
        }
    }

    public function testCheckinOverAjaxAnswersWithJson(): void
    {
        $client = $this->participant('ajax');
        $token = $client->csrf('/participant/dashboard.php');
        $ajax = ['X-Requested-With: XMLHttpRequest'];

        $response = $client->post('/ajax/checkin.php', ['csrf' => $token], $ajax);
        $this->assertSame(200, $response->status);
        $this->assertSame(true, json_decode($response->body, true)['success']);

        $this->assertSame(409, $client->post('/ajax/checkin.php', ['csrf' => $token], $ajax)->status);

        $forged = $client->post('/ajax/checkin.php', ['csrf' => 'forged'], $ajax);
        $this->assertSame(403, $forged->status);
        $this->assertSame(false, json_decode($forged->body, true)['success']);
    }

    public function testCheckinWorksWithoutJavaScript(): void
    {
        $client = $this->participant('no_js');
        $response = $client->submit('/participant/dashboard.php', [], '/ajax/checkin.php');

        $this->assertSame('/participant/dashboard.php', $response->location());
        $this->assertContains('Check-in successful', $this->flashes($client, '/participant/dashboard.php'));
    }

    private function assertNotMatches(string $pattern, string $subject, string $message): void
    {
        if (preg_match($pattern, $subject, $m)) {
            $this->fail($message . ': found ' . $m[0]);
        }
    }
}
