-- Run against your `ecotrack` database if accounts already exist:
--   mysql -u root ecotrack < scripts/update_admin_mod_passwords.sql
-- Passwords: admin -> EcoAdmin2026, moderator -> EcoMod2026

UPDATE users SET password = '$2y$10$TWyue8NQZBGzkVpeMfNFuerQFYHdz1iCdzrfdKOBmCbPnvYv/HCve'
 WHERE email = 'admin@ecotrack.com';

UPDATE users SET password = '$2y$10$ki7KA8v9RVP352M0S5vofu4WEwJQY0nE51u8Vh6w5z.8wS7dvUIea'
 WHERE email = 'mod@ecotrack.com';
