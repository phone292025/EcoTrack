<?php
/**
 * Runs the application under PHP's built-in web server for the HTTP tests,
 * pointed at the test database, and collects its error log so any PHP
 * warning raised while serving a page fails the run.
 *
 * url()          the app at the web root, as `php -S ... router.php` serves it
 * subfolderUrl() the app under /ecotrack/, like a XAMPP htdocs install
 */
final class TestServer
{
    /** @var array<string, array{process: resource, url: string, log: string, dir: ?string}> */
    private static array $servers = [];

    public static function url(): string
    {
        if (!isset(self::$servers['root'])) {
            self::$servers['root'] = self::start(self::projectRoot(), 'router.php', null);
        }
        return self::$servers['root']['url'];
    }

    /**
     * The app served from a subfolder, or null when this machine cannot
     * create the symlink that needs (some Windows setups).
     */
    public static function subfolderUrl(): ?string
    {
        if (!isset(self::$servers['subfolder'])) {
            $docroot = sys_get_temp_dir() . '/ecotrack-docroot-' . bin2hex(random_bytes(4));
            mkdir($docroot);
            if (!@symlink(self::projectRoot(), $docroot . '/ecotrack')) {
                rmdir($docroot);
                return null;
            }
            $server = self::start($docroot, null, $docroot);
            $server['url'] .= '/ecotrack';
            self::$servers['subfolder'] = $server;
        }
        return self::$servers['subfolder']['url'];
    }

    private static function projectRoot(): string
    {
        return (string)realpath(__DIR__ . '/../..');
    }

    private static function start(string $docroot, ?string $router, ?string $cleanupDir): array
    {
        $port = self::freePort();
        $log = (string)tempnam(sys_get_temp_dir(), 'ecotrack-server-');

        $command = [
            PHP_BINARY,
            '-d', 'display_errors=1',
            '-d', 'log_errors=1',
            '-d', 'error_reporting=-1',
            '-S', '127.0.0.1:' . $port,
            '-t', $docroot,
        ];
        if ($router !== null) {
            $command[] = $router;
        }

        $env = getenv();
        $env['ECOTRACK_DB_NAME'] = DB_NAME;
        $env['ECOTRACK_APP_DEBUG'] = '1';

        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $docroot,
            $env
        );

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket) {
                fclose($socket);
                return ['process' => $process, 'url' => 'http://127.0.0.1:' . $port, 'log' => $log, 'dir' => $cleanupDir];
            }
            usleep(100000);
        }

        throw new RuntimeException('Test server did not start. Log: ' . file_get_contents($log));
    }

    /**
     * Stop every server and return each PHP error line they logged.
     *
     * @return string[]
     */
    public static function stop(): array
    {
        $errors = [];

        foreach (self::$servers as $server) {
            proc_terminate($server['process']);
            proc_close($server['process']);

            foreach (file($server['log'], FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (preg_match('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error|Error)/i', $line)) {
                    $errors[] = $line;
                }
            }
            @unlink($server['log']);

            if ($server['dir'] !== null) {
                @unlink($server['dir'] . '/ecotrack');
                @rmdir($server['dir']);
            }
        }

        self::$servers = [];

        return $errors;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int)substr((string)strrchr((string)$name, ':'), 1);
    }
}
