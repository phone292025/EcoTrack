<?php
/**
 * CLI: php scripts/check_login_users.php
 * Verifies seeded admin/moderator rows and password hashes.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../database/db.php';

$pdo = getPDO();
$emails = ['admin@ecotrack.com', 'mod@ecotrack.com'];
$expect = [
    'admin@ecotrack.com' => 'admin1234',
    'mod@ecotrack.com' => 'mod123',
];

foreach ($emails as $email) {
    $stmt = $pdo->prepare(
        'SELECT user_id, username, email, password
         FROM users
         WHERE email = ?'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    echo "=== {$email} ===\n";
    if (!$row) {
        echo "  ROW MISSING - user was never inserted or email differs.\n\n";
        continue;
    }

    echo "  user_id={$row['user_id']} username={$row['username']}\n";
    $hash = $row['password'];
    $pw = $expect[$email];
    $ok = password_verify($pw, $hash);
    // Never print the hash or its length — that is material for an attacker
    // and the pass/fail result is all this check actually needs to report.
    echo "  seeded password verifies: " . ($ok ? "OK\n" : "FAIL\n");
    echo "\n";
}

echo "All emails in users table:\n";
foreach ($pdo->query('SELECT email, username, role FROM users ORDER BY user_id') as $row) {
    echo "  {$row['email']} ({$row['username']}) role={$row['role']}\n";
}
