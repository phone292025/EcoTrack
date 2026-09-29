<?php
/**
 * The app installed in a subfolder, as the README's XAMPP instructions set
 * it up (htdocs/ecotrack). Every link and redirect must carry the /ecotrack
 * prefix exactly once.
 */
final class HttpSubfolderTest extends HttpTestCase
{
    private function subfolderClient(): ?HttpClient
    {
        $url = TestServer::subfolderUrl();

        return $url === null ? null : new HttpClient($url);
    }

    /**
     * Regression: redirectToSelf() used to add the base path to a URI that
     * already contained it, sending every form under /ecotrack/ to
     * /ecotrack/ecotrack/... after saving.
     */
    public function testFormRedirectsKeepASinglePrefix(): void
    {
        $client = $this->subfolderClient();
        if ($client === null) {
            return; // cannot create a symlink on this machine
        }

        $login = $client->login(...self::ADMIN);
        $this->assertSame('/ecotrack/admin/dashboard.php', $login->location());

        $response = $client->submit('/admin/announcements.php', [
            'post_action' => 'create',
            'ann_title' => 'Posted from a subfolder',
        ]);
        $this->assertSame('/ecotrack/admin/announcements.php', $response->location());

        $page = $client->get('/admin/announcements.php');
        $this->assertPageOk($page, 'announcements under /ecotrack');
        $this->assertContains('Posted from a subfolder', $page->body);
        $this->assertContains('href="/ecotrack/assets/css/style.css"', $page->body);
    }
}
