<?php
/**
 * CLI: php scripts/check_login_users.php
 * Verifies seeded admin/moderator rows and password hashes.
 */
require_once __DIR__ . '/../includes/db.php';

$pdo = getPDO();
$emails = ['admin@ecotrack.com', 'mod@ecotrack.com'];
$expect = [
    'admin@ecotrack.com' => 'admin1234',
    'mod@ecotrack.com' => 'mod123',
];

foreach ($emails as $email) {
    $stmt = $pdo->prepare(
        'SELECT user_id, username, email, LENGTH(password) AS pwlen, password
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

    echo "  user_id={$row['user_id']} username={$row['username']} pwlen={$row['pwlen']}\n";
    $hash = $row['password'];
    $pw = $expect[$email];
    $ok = password_verify($pw, $hash);
    echo "  password_verify('{$pw}', stored_hash): " . ($ok ? "OK\n" : "FAIL\n");
    if (!$ok) {
        echo "  hash prefix: " . substr($hash, 0, 30) . "...\n";
    }
    echo "\n";
}

echo "All emails in users table:\n";
foreach ($pdo->query('SELECT email, username, role FROM users ORDER BY user_id') as $row) {
    echo "  {$row['email']} ({$row['username']}) role={$row['role']}\n";
}
