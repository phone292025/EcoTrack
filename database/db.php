<?php
/**
 * EcoTrack — Database Connection (PDO Singleton)
 * File: database/db.php
 *
 * Usage:  $pdo = getPDO();
 *
 * This file only opens the connection. Schema creation and migration live in
 * scripts/migrate.php and run once during setup — never on a page request.
 *
 * Another laptop:
 *   • Import database/ecotrack.sql into MySQL/MariaDB.
 *   • Optional: copy database/db.local.example.php → db.local.php and set DB_PASS / DB_HOST.
 *   • Or set env vars: ECOTRACK_DB_HOST, ECOTRACK_DB_NAME, ECOTRACK_DB_USER, ECOTRACK_DB_PASS.
 *   • Then run: php scripts/migrate.php
 */
if (is_file(__DIR__ . '/db.local.php')) {
    require_once __DIR__ . '/db.local.php';
}

if (!defined('DB_HOST')) {
    $v = getenv('ECOTRACK_DB_HOST');
    define('DB_HOST', ($v !== false && $v !== '') ? $v : 'localhost');
}
if (!defined('DB_NAME')) {
    $v = getenv('ECOTRACK_DB_NAME');
    define('DB_NAME', ($v !== false && $v !== '') ? $v : 'ecotrack');
}
if (!defined('DB_USER')) {
    $v = getenv('ECOTRACK_DB_USER');
    define('DB_USER', ($v !== false && $v !== '') ? $v : 'root');
}
if (!defined('DB_PASS')) {
    $v = getenv('ECOTRACK_DB_PASS');
    define('DB_PASS', ($v !== false && $v !== '') ? $v : '');
}
if (!defined('DB_CHARSET')) {
    $v = getenv('ECOTRACK_DB_CHARSET');
    define('DB_CHARSET', ($v !== false && $v !== '') ? $v : 'utf8mb4');
}

/**
 * Show the demo account details on the login page.
 * Set DEMO_MODE to false in database/db.local.php for any non-demo deployment.
 */
if (!defined('DEMO_MODE')) {
    $v = getenv('ECOTRACK_DEMO_MODE');
    define('DEMO_MODE', $v === false || $v === '' || filter_var($v, FILTER_VALIDATE_BOOLEAN));
}

function normalizeConnectionCandidates(array $values): array
{
    $normalized = [];

    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value === '' || in_array($value, $normalized, true)) {
            continue;
        }
        $normalized[] = $value;
    }

    return $normalized;
}

function getConnectionAttempts(): array
{
    $hosts = normalizeConnectionCandidates([
        DB_HOST,
        DB_HOST === 'localhost' ? '127.0.0.1' : 'localhost',
    ]);

    $databases = normalizeConnectionCandidates([
        DB_NAME,
    ]);

    $attempts = [];
    foreach ($hosts as $host) {
        foreach ($databases as $database) {
            $attempts[] = [
                'host' => $host,
                'database' => $database,
            ];
        }
    }

    return $attempts;
}

function getPDO(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $errors = [];
        foreach (getConnectionAttempts() as $attempt) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $attempt['host'],
                $attempt['database'],
                DB_CHARSET
            );

            try {
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                return $pdo;
            } catch (PDOException $e) {
                $errors[] = sprintf(
                    '[host=%s db=%s] %s',
                    $attempt['host'],
                    $attempt['database'],
                    $e->getMessage()
                );
            }
        }

        error_log('[EcoTrack DB] ' . implode(' | ', $errors));
        http_response_code(500);
        exit('Database connection failed. Please import database/ecotrack.sql and run: php scripts/migrate.php');
    }

    return $pdo;
}

/**
 * True when a duplicate-key constraint rejected the statement.
 * Use this instead of assuming every PDOException is a duplicate.
 */
function isDuplicateKeyError(PDOException $e): bool
{
    return (int)($e->errorInfo[1] ?? 0) === 1062;
}
