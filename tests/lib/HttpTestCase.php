<?php
/**
 * Base class for tests that drive the application over HTTP.
 */
abstract class HttpTestCase extends TestCase
{
    public const ADMIN = ['admin', 'EcoAdmin2026'];
    public const MODERATOR = ['moderator', 'EcoMod2026'];

    protected function client(): HttpClient
    {
        return new HttpClient(TestServer::url());
    }

    /** A client logged in with the given credentials. */
    protected function loggedIn(string $identifier, string $password): HttpClient
    {
        $client = $this->client();
        $response = $client->login($identifier, $password);
        $this->assertSame(302, $response->status, 'login status for ' . $identifier);
        $this->assertNotContains('/login.php', $response->location(), 'login for ' . $identifier . ' bounced back');

        return $client;
    }

    protected function admin(): HttpClient
    {
        return $this->loggedIn(...self::ADMIN);
    }

    protected function moderator(): HttpClient
    {
        return $this->loggedIn(...self::MODERATOR);
    }

    /** Register a participant through the real form and log them in. */
    protected function participant(string $username = 'tester', string $password = 'Password1'): HttpClient
    {
        $client = $this->client();
        $response = $client->submit('/register.php', [
            'username' => $username,
            'email' => $username . '@example.test',
            'password' => $password,
            'confirm_password' => $password,
        ]);
        $this->assertSame('/login.php', $response->location(), 'registration of ' . $username);

        return $this->loggedIn($username, $password);
    }

    /** Assert a page rendered successfully and without any PHP error text. */
    protected function assertPageOk(HttpResponse $response, string $label): void
    {
        $this->assertSame(200, $response->status, $label . ' status');
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Uncaught', 'Stack trace'] as $marker) {
            $this->assertNotContains($marker, $response->body, $label . ' contains PHP error output "' . $marker . '"');
        }
    }

    /** Text of the flash messages rendered on a page. */
    protected function flashes(HttpClient $client, string $path): string
    {
        $body = $client->get($path)->body;
        preg_match_all('/class="flash-message[^"]*"[^>]*>([^<]*)/', $body, $m);

        return implode(' | ', array_map('trim', $m[1]));
    }
}
