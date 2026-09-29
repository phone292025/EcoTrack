<?php
/**
 * Every page, as every role: renders cleanly for the roles allowed to see it
 * and is refused for everyone else.
 */
require_once __DIR__ . '/lib/HttpTestCase.php';

final class HttpSmokeTest extends HttpTestCase
{
    private const PARTICIPANT_PAGES = [
        '/participant/dashboard.php',
        '/participant/log_activity.php',
        '/participant/challenges.php',
        '/participant/shop.php',
        '/participant/points.php',
        '/participant/leaderboard.php',
        '/participant/profile.php',
    ];

    private const MODERATOR_PAGES = [
        '/moderator/dashboard.php',
        '/moderator/review_submissions.php',
        '/moderator/participant_table.php',
        '/moderator/create_challenge.php',
        '/moderator/eco_tips.php',
    ];

    private const ADMIN_PAGES = [
        '/admin/dashboard.php',
        '/admin/review_submissions.php',
        '/admin/user_management.php',
        '/admin/participant_table.php',
        '/admin/challenge_management.php',
        '/admin/eco_tips.php',
        '/admin/rewards_management.php',
        '/admin/badges_management.php',
        '/admin/announcements.php',
    ];

    public function testGuestPagesRender(): void
    {
        $client = $this->client();
        foreach (['/', '/index.php', '/login.php', '/register.php'] as $path) {
            $this->assertPageOk($client->get($path), $path);
        }
    }

    public function testGuestIsSentToLogin(): void
    {
        $client = $this->client();
        foreach (array_merge(self::PARTICIPANT_PAGES, self::MODERATOR_PAGES, self::ADMIN_PAGES) as $path) {
            $response = $client->get($path);
            $this->assertSame(302, $response->status, $path);
            $this->assertSame('/login.php', $response->location(), $path);
        }
    }

    public function testSeededAdminAndModeratorCanLogIn(): void
    {
        $this->assertSame('/admin/dashboard.php', $this->client()->login(...self::ADMIN)->location());
        $this->assertSame('/moderator/dashboard.php', $this->client()->login(...self::MODERATOR)->location());
    }

    public function testParticipantPages(): void
    {
        $client = $this->participant();
        foreach (self::PARTICIPANT_PAGES as $path) {
            $this->assertPageOk($client->get($path), $path);
        }
        foreach (array_merge(self::MODERATOR_PAGES, self::ADMIN_PAGES) as $path) {
            $this->assertSame(403, $client->get($path)->status, $path);
        }
    }

    public function testModeratorPages(): void
    {
        $client = $this->moderator();
        foreach (self::MODERATOR_PAGES as $path) {
            $this->assertPageOk($client->get($path), $path);
        }
        foreach (array_merge(self::PARTICIPANT_PAGES, self::ADMIN_PAGES) as $path) {
            $this->assertSame(403, $client->get($path)->status, $path);
        }
    }

    public function testAdminPages(): void
    {
        $client = $this->admin();
        foreach (array_merge(self::ADMIN_PAGES, ['/moderator/dashboard.php', '/moderator/review_submissions.php']) as $path) {
            $this->assertPageOk($client->get($path), $path);
        }
        foreach (self::PARTICIPANT_PAGES as $path) {
            $this->assertSame(403, $client->get($path)->status, $path);
        }
    }

    /**
     * Regression: the pagination links were built with array_filter(), which
     * dropped a search for "0", so "Next" showed the unfiltered list.
     */
    public function testParticipantTablePagingKeepsTheSearch(): void
    {
        $hash = password_hash('Password1', PASSWORD_DEFAULT);
        $insert = getPDO()->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)');
        for ($i = 1; $i <= 30; $i++) {
            $insert->execute(['user0_' . $i, 'user0_' . $i . '@example.test', $hash]);
        }

        $body = $this->moderator()->get('/moderator/participant_table.php?q=0')->body;
        $this->assertContains('Page 1 of 2', $body);
        $this->assertContains('href="participant_table.php?q=0&amp;page=2"', $body);
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $client = $this->participant();
        $response = $client->post('/participant/dashboard.php', ['action' => 'save_goal', 'target' => 50, 'period' => 'weekly']);
        $this->assertSame(403, $response->status);
    }

    public function testFullParticipantJourney(): void
    {
        $participant = $this->participant('journey');
        $moderator = $this->moderator();

        // Log an activity, have it approved, and see the points land.
        $participant->submit('/participant/log_activity.php', [
            'cat_id' => 1,
            'description' => 'Sorted a week of recycling at home',
        ]);
        $logId = (int)getPDO()->query("SELECT log_id FROM activity_logs ORDER BY log_id DESC LIMIT 1")->fetchColumn();
        $this->assertTrue($logId > 0, 'activity was stored');

        $moderator->submit('/moderator/review_submissions.php', ['log_id' => $logId, 'submission_action' => 'approve']);
        $this->assertContains('10 pts', $participant->get('/participant/points.php')->body);

        // Redeem something affordable after topping up the balance.
        awardPoints((int)getPDO()->query("SELECT user_id FROM users WHERE username = 'journey'")->fetchColumn(), 200, 'adjustment', 'Test top-up');
        $response = $participant->submit('/participant/shop.php', ['reward_id' => 3]);
        $this->assertSame(302, $response->status);
        $this->assertContains('Redeemed', $this->flashes($participant, '/participant/shop.php'));
    }
}
