<?php
/**
 * EcoTrack test runner.
 *
 *   php tests/run.php            run everything
 *   php tests/run.php Ledger     run test classes/methods whose name contains "Ledger"
 *
 * The suite builds a throwaway database named DB_NAME (default ecotrack_test)
 * from database/ecotrack.sql and empties it before every test. It refuses to
 * run against any database whose name does not end in "_test", so it can
 * never touch real data.
 *
 * Database credentials come from the usual places (database/db.local.php or
 * the ECOTRACK_DB_* environment variables). The account needs permission to
 * create and drop the test database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Any PHP warning inside the code under test is a bug, so make it fail loudly.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$testDbName = getenv('ECOTRACK_TEST_DB_NAME') ?: 'ecotrack_test';
putenv('ECOTRACK_DB_NAME=' . $testDbName);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/lib/TestCase.php';
require_once __DIR__ . '/lib/TestDatabase.php';
require_once __DIR__ . '/lib/HttpClient.php';
require_once __DIR__ . '/lib/TestServer.php';
require_once __DIR__ . '/lib/HttpTestCase.php';
require_once __DIR__ . '/lib/Fixtures.php';

if (!str_ends_with(DB_NAME, '_test')) {
    fwrite(STDERR, "Refusing to run: the database name is \"" . DB_NAME . "\".\n"
        . "database/db.local.php probably defines DB_NAME. The suite only runs against a database\n"
        . "whose name ends in _test. Remove DB_NAME from db.local.php or set ECOTRACK_TEST_DB_NAME.\n");
    exit(2);
}

try {
    TestDatabase::create();
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not create the test database: ' . $e->getMessage() . "\n");
    exit(2);
}

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

$passed = 0;
$failures = [];
$started = microtime(true);

foreach ($files as $file) {
    $before = get_declared_classes();
    require_once $file;
    $classes = array_filter(
        array_diff(get_declared_classes(), $before),
        static fn (string $class): bool => is_subclass_of($class, TestCase::class)
    );

    foreach ($classes as $class) {
        $methods = array_filter(
            get_class_methods($class),
            static fn (string $method): bool => str_starts_with($method, 'test')
        );

        foreach ($methods as $method) {
            $name = $class . '::' . $method;
            if ($filter !== '' && stripos($name, $filter) === false) {
                continue;
            }

            TestDatabase::reset();
            $_SESSION = [];
            $test = new $class();

            try {
                $test->runSetUp();
                try {
                    $test->$method();
                } finally {
                    $test->runTearDown();
                }
                $passed++;
                echo '.';
            } catch (AssertionFailed $e) {
                $failures[] = [$name, 'FAIL', $e->getMessage(), $e];
                echo 'F';
            } catch (Throwable $e) {
                $failures[] = [$name, 'ERROR', get_class($e) . ': ' . $e->getMessage(), $e];
                echo 'E';
            }
        }
    }
}

foreach (TestServer::stop() as $line) {
    $failures[] = ['web server log', 'ERROR', $line, null];
}

$elapsed = microtime(true) - $started;
echo "\n\n";

foreach ($failures as $i => [$name, $kind, $message, $exception]) {
    echo ($i + 1) . ") {$kind} {$name}\n   {$message}\n";
    if ($exception instanceof Throwable) {
        foreach ($exception->getTrace() as $frame) {
            if (isset($frame['file']) && !str_contains($frame['file'], '/tests/lib/')) {
                echo '   at ' . str_replace(dirname(__DIR__) . '/', '', $frame['file']) . ':' . $frame['line'] . "\n";
                break;
            }
        }
    }
    echo "\n";
}

printf("%d passed, %d failed (%.1fs)\n", $passed, count($failures), $elapsed);
exit($failures ? 1 : 0);
