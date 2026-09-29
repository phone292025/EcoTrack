<?php
/**
 * CLI: php scripts/apply_admin_mod_passwords.php
 *
 * Reset the demo admin and moderator accounts to the passwords listed in
 * includes/rules.php (DEMO_ACCOUNTS). Hashes are generated fresh here rather
 * than pasted in, so they cannot be mangled on the way into the file.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/rules.php';

$stmt = getPDO()->prepare('UPDATE users SET password = ? WHERE email = ?');

foreach (DEMO_ACCOUNTS as $account) {
    $stmt->execute([password_hash($account['password'], PASSWORD_DEFAULT), $account['email']]);
    echo ($stmt->rowCount() > 0 ? 'Updated ' : 'No account for ') . $account['email'] . "\n";
}
