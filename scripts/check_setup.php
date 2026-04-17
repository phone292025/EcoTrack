<?php
/**
 * CLI: php scripts/check_setup.php
 * Quick environment check for running EcoTrack on a new laptop.
 */
require_once __DIR__ . '/../includes/db.php';

$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$uploadDir = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'evidence';

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

if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}
$uploadOk = is_dir($uploadDir) && is_writable($uploadDir);
printResult($uploadOk, 'Upload folder', $uploadDir);
$allGood = $allGood && $uploadOk;

$databaseName = null;
$dbErrors = [];
$pdo = tryDatabaseConnection($databaseName, $dbErrors);
$dbOk = $pdo instanceof PDO;
printResult($dbOk, 'Database connection', $dbOk ? $databaseName : implode(' | ', $dbErrors));
$allGood = $allGood && $dbOk;

if ($pdo instanceof PDO) {
    try {
        $tables = (int)$pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()"
        )->fetchColumn();
        printResult($tables >= 10, 'Database tables found', (string)$tables);
        $allGood = $allGood && ($tables >= 10);
    } catch (Throwable $e) {
        printResult(false, 'Database tables found', $e->getMessage());
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
}

echo PHP_EOL;
if ($allGood) {
    echo "EcoTrack is ready. Start the server with:" . PHP_EOL;
    echo "php -S localhost:8000" . PHP_EOL;
    exit(0);
}

echo "Some checks failed. Fix the failed item(s) above, then run:" . PHP_EOL;
echo "php scripts/check_setup.php" . PHP_EOL;
exit(1);
