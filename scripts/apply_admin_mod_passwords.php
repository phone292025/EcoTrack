<?php
/** php scripts/apply_admin_mod_passwords.php */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../database/db.php';
$pdo = getPDO();
$pdo->prepare(
    'UPDATE users SET password = ? WHERE email = ?'
)->execute([
    'y$TWyue8NQZBGzkVpeMfNFuerQFYHdz1iCdzrfdKOBmCbPnvYv/HCve',
    'admin@ecotrack.com',
]);
$pdo->prepare(
    'UPDATE users SET password = ? WHERE email = ?'
)->execute([
    'y$ki7KA8v9RVP352M0S5vofu4WEwJQY0nE51u8Vh6w5z.8wS7dvUIea',
    'mod@ecotrack.com',
]);
echo "Updated admin + moderator password hashes.\n";
