-- Run against your `ecotrack` database if accounts already exist:
--   mysql -u root ecotrack < scripts/update_admin_mod_passwords.sql
-- Passwords: admin -> admin1234, moderator -> mod123

UPDATE users SET password = '$2y$10$TFwqkDalLGsVc2eUl2UoQ.e6zFYiiqfmlECUA.Fl5J.9.Ax.iGpga'
 WHERE email = 'admin@ecotrack.com';

UPDATE users SET password = '$2y$10$43jcNbWQAmePyIJv5sKet.pXb8nt.xaRV8LPsJ.bU8547DCTXApP6'
 WHERE email = 'mod@ecotrack.com';
