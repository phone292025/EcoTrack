<?php
/** php scripts/apply_admin_mod_passwords.php */
require_once __DIR__ . '/../includes/db.php';
$pdo = getPDO();
$pdo->prepare(
    'UPDATE users SET password = ? WHERE email = ?'
)->execute([
    '$2y$10$TFwqkDalLGsVc2eUl2UoQ.e6zFYiiqfmlECUA.Fl5J.9.Ax.iGpga',
    'admin@ecotrack.com',
]);
$pdo->prepare(
    'UPDATE users SET password = ? WHERE email = ?'
)->execute([
    '$2y$10$43jcNbWQAmePyIJv5sKet.pXb8nt.xaRV8LPsJ.bU8547DCTXApP6',
    'mod@ecotrack.com',
]);
echo "Updated admin + moderator password hashes.\n";
