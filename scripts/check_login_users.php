<?php
/**
 * CLI: php scripts/check_login_users.php
 *
 * Confirms the demo admin and moderator accounts exist and that their stored
 * password hashes still match the demo passwords, and that no account has a
 * password hash PHP cannot verify at all. Exits with status 1 on any problem
 * so it can gate a CI run.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/rules.php';

$pdo = getPDO();
$ok = true;

$stmt = $pdo->prepare('SELECT user_id, username, role, password FROM users WHERE email = ?');

foreach (DEMO_ACCOUNTS as $account) {
    echo "=== {$account['email']} ===\n";
    $stmt->execute([$account['email']]);
    $row = $stmt->fetch();

    if (!$row) {
        echo "  [FAIL] account missing - import database/ecotrack.sql or run php scripts/migrate.php\n\n";
        $ok = false;
        continue;
    }

    echo "  user_id={$row['user_id']} username={$row['username']} role={$row['role']}\n";

    // Never print the hash or its length. Pass/fail is all this needs to say.
    $verifies = password_verify($account['password'], (string)$row['password']);
    echo '  demo password verifies: ' . ($verifies ? "[OK]\n" : "[FAIL] run: php scripts/apply_admin_mod_passwords.php\n");
    $ok = $ok && $verifies;

    if ($row['role'] !== $account['role']) {
        echo "  [FAIL] expected role {$account['role']}\n";
        $ok = false;
    }
    echo "\n";
}

// A hash PHP does not recognise can never verify, which locks that account
// out without any error message.
$broken = [];
foreach ($pdo->query('SELECT username, password FROM users ORDER BY user_id') as $row) {
    if ((password_get_info((string)$row['password'])['algo'] ?? null) === null) {
        $broken[] = $row['username'];
    }
}

if ($broken) {
    echo '[FAIL] accounts with an unusable password hash: ' . implode(', ', $broken) . "\n";
    $ok = false;
} else {
    echo "[OK] every account has a valid password hash\n";
}

exit($ok ? 0 : 1);
