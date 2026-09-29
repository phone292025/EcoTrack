<?php
/**
 * CLI: php scripts/check_setup.php
 * Quick environment check for running EcoTrack on a new laptop.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/schema.php';

$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$uploadDirs = [
    $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'evidence',
    $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars',
];

function printResult(bool $ok, string $label, string $detail = ''): void
{
    $status = $ok ? '[OK]' : '[FAIL]';
    echo $status . ' ' . $label;
    if ($detail !== '') {
        echo ' - ' . $detail;
    }
    echo PHP_EOL;
}

function tryDatabaseConnection(?string &$databaseName = null, array &$errors = []): ?PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    foreach (getConnectionAttempts() as $attempt) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $attempt['host'],
            $attempt['database'],
            DB_CHARSET
        );

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
            return $pdo;
        } catch (PDOException $e) {
            $errors[] = sprintf(
                'host=%s db=%s -> %s',
                $attempt['host'],
                $attempt['database'],
                $e->getMessage()
            );
        }
    }

    return null;
}

echo "EcoTrack setup check" . PHP_EOL;
echo "Project: {$projectRoot}" . PHP_EOL . PHP_EOL;

$allGood = true;

$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
printResult($phpOk, 'PHP version', PHP_VERSION);
$allGood = $allGood && $phpOk;

$pdoOk = extension_loaded('pdo');
printResult($pdoOk, 'PHP extension pdo');
$allGood = $allGood && $pdoOk;

$pdoMysqlOk = extension_loaded('pdo_mysql');
printResult($pdoMysqlOk, 'PHP extension pdo_mysql');
$allGood = $allGood && $pdoMysqlOk;

foreach ($uploadDirs as $uploadDir) {
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $uploadOk = is_dir($uploadDir) && is_writable($uploadDir);
    printResult($uploadOk, 'Upload folder', $uploadDir);
    $allGood = $allGood && $uploadOk;
}

$databaseName = null;
$dbErrors = [];
$pdo = tryDatabaseConnection($databaseName, $dbErrors);
$dbOk = $pdo instanceof PDO;
printResult($dbOk, 'Database connection', $dbOk ? $databaseName : implode(' | ', $dbErrors));
$allGood = $allGood && $dbOk;

if ($pdo instanceof PDO) {
    try {
        $expected = array_keys(schemaDefinition()['tables']);
        $present = $pdo->query(
            "SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = DATABASE()"
        )->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff($expected, $present);
        printResult(
            !$missing,
            'Database tables',
            $missing
                ? 'missing ' . implode(', ', $missing) . ' (run: php scripts/migrate.php)'
                : count($expected) . ' of ' . count($expected)
        );
        $allGood = $allGood && !$missing;
    } catch (Throwable $e) {
        printResult(false, 'Database tables', $e->getMessage());
        $allGood = false;
    }

    try {
        $stmt = $pdo->query(
            "SELECT username, email, role
             FROM users
             WHERE username IN ('admin', 'moderator')
             ORDER BY user_id"
        );
        $rows = $stmt->fetchAll();
        $summary = [];
        foreach ($rows as $row) {
            $summary[] = "{$row['username']} / {$row['email']} / {$row['role']}";
        }
        $seedOk = count($rows) >= 2;
        printResult($seedOk, 'Default accounts', implode('; ', $summary));
        $allGood = $allGood && $seedOk;
    } catch (Throwable $e) {
        printResult(false, 'Default accounts', $e->getMessage());
        $allGood = false;
    }

    // An account row is no use if its password hash cannot be verified.
    try {
        $broken = [];
        foreach ($pdo->query('SELECT username, password FROM users') as $row) {
            if ((password_get_info((string)$row['password'])['algo'] ?? null) === null) {
                $broken[] = $row['username'];
            }
        }
        printResult(
            !$broken,
            'Password hashes',
            $broken ? 'unusable for ' . implode(', ', $broken) . ' (run: php scripts/apply_admin_mod_passwords.php)' : 'all valid'
        );
        $allGood = $allGood && !$broken;
    } catch (Throwable $e) {
        printResult(false, 'Password hashes', $e->getMessage());
        $allGood = false;
    }
}

echo PHP_EOL;
if ($allGood) {
    echo "EcoTrack is ready. Start the server with:" . PHP_EOL;
    echo "php -S localhost:8000 router.php" . PHP_EOL;
    exit(0);
}

echo "Some checks failed. Fix the failed item(s) above, then run:" . PHP_EOL;
echo "php scripts/migrate.php" . PHP_EOL;
echo "php scripts/check_setup.php" . PHP_EOL;
exit(1);
