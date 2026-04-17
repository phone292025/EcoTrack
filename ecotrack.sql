-- ============================================================
-- EcoTrack Database Schema
-- Import with:
--   mysql -u root < ecotrack.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS ecotrack
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE ecotrack;

-- --------------------------------------------------------
-- 1. USERS
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(100) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('participant','moderator','admin') NOT NULL DEFAULT 'participant',
  points INT NOT NULL DEFAULT 0,
  streak INT NOT NULL DEFAULT 0,
  last_checkin DATE DEFAULT NULL,
  avatar VARCHAR(255) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- --------------------------------------------------------
-- 2. ACTIVITY CATEGORIES
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  cat_id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  icon VARCHAR(100) DEFAULT NULL,
  co2_per_point DECIMAL(6,4) DEFAULT 0.0100
);

-- --------------------------------------------------------
-- 3. ACTIVITY LOGS
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_logs (
  log_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  cat_id INT NOT NULL,
  description TEXT,
  evidence VARCHAR(255) DEFAULT NULL,
  points INT NOT NULL DEFAULT 0,
  status ENUM('pending','approved','rejected','flagged') DEFAULT 'pending',
  flagged_by INT DEFAULT NULL,
  reviewed_by INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (cat_id) REFERENCES categories(cat_id),
  FOREIGN KEY (flagged_by) REFERENCES users(user_id) ON DELETE SET NULL,
  FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- --------------------------------------------------------
-- 4. CHALLENGES
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS challenges (
  challenge_id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  description TEXT,
  cat_id INT DEFAULT NULL,
  difficulty ENUM('easy','medium','hard') DEFAULT 'easy',
  points INT NOT NULL DEFAULT 10,
  start_date DATE DEFAULT NULL,
  end_date DATE DEFAULT NULL,
  created_by INT DEFAULT NULL,
  status ENUM('draft','active','closed') DEFAULT 'draft',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cat_id) REFERENCES categories(cat_id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- --------------------------------------------------------
-- 5. CHALLENGE PARTICIPANTS
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS challenge_participants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  challenge_id INT NOT NULL,
  user_id INT NOT NULL,
  joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  completed TINYINT(1) DEFAULT 0,
  completed_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_cp (challenge_id, user_id),
  FOREIGN KEY (challenge_id) REFERENCES challenges(challenge_id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- --------------------------------------------------------
-- 6. BADGES
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS badges (
  badge_id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  description TEXT,
  icon VARCHAR(255) DEFAULT NULL,
  criteria VARCHAR(255) DEFAULT NULL,
  created_by INT DEFAULT NULL,
  FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- --------------------------------------------------------
-- 7. USER BADGES
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_badges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  badge_id INT NOT NULL,
  earned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ub (user_id, badge_id),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (badge_id) REFERENCES badges(badge_id) ON DELETE CASCADE
);

-- --------------------------------------------------------
-- 8. GOALS
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS goals (
  goal_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  target INT NOT NULL,
  period ENUM('weekly','monthly') DEFAULT 'weekly',
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  bonus_awarded TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- --------------------------------------------------------
-- 9. REWARDS
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS rewards (
  reward_id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  description TEXT,
  image VARCHAR(255) DEFAULT NULL,
  category ENUM('Lifestyle','Campus','Eco Essentials') DEFAULT 'Lifestyle',
  point_cost INT NOT NULL DEFAULT 50,
  stock INT NOT NULL DEFAULT 0,
  active TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- --------------------------------------------------------
-- 10. REDEMPTIONS
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS redemptions (
  redemption_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  reward_id INT NOT NULL,
  points_spent INT NOT NULL,
  redeemed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (reward_id) REFERENCES rewards(reward_id) ON DELETE CASCADE
);

-- --------------------------------------------------------
-- 11. POINTS LEDGER
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS points_transactions (
  txn_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  delta INT NOT NULL,
  reason VARCHAR(255) DEFAULT NULL,
  ref_id INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- --------------------------------------------------------
-- 12. DAILY CHECK-INS
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS daily_checkins (
  checkin_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  checkin_date DATE NOT NULL,
  UNIQUE KEY uq_checkin (user_id, checkin_date),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- --------------------------------------------------------
-- 13. ECO TIPS
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS eco_tips (
  tip_id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  body TEXT,
  created_by INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- --------------------------------------------------------
-- 14. ANNOUNCEMENTS
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS announcements (
  ann_id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  body TEXT,
  created_by INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO categories (name, icon, co2_per_point) VALUES
  ('Recycling', 'icon_recycling.svg', 0.0100),
  ('Plastic Reduction', 'icon_plastic.svg', 0.0150),
  ('Energy Saving', 'icon_energy.svg', 0.0200),
  ('Green Transport', 'icon_transport.svg', 0.0250);

INSERT INTO badges (name, description, icon, criteria) VALUES
  ('First Log', 'Logged your very first eco-activity!', 'badge_firstlog.svg', 'logs>=1'),
  ('Green Starter', 'Earned 50 points - you are on your way!', 'badge_50pts.svg', 'points>=50'),
  ('Eco Achiever', 'Reached 100 points. Great commitment!', 'badge_100pts.svg', 'points>=100'),
  ('Eco Champion', 'Reached 500 points. You are a champion!', 'badge_500pts.svg', 'points>=500'),
  ('7-Day Streak', 'Logged activities 7 days in a row!', 'badge_streak7.svg', 'streak>=7'),
  ('30-Day Streak', 'Incredible! 30 consecutive days of eco-action!', 'badge_streak30.svg', 'streak>=30'),
  ('Goal Crusher', 'Achieved your personal points goal!', 'badge_goal.svg', 'goal_achieved');

INSERT INTO rewards (name, description, category, point_cost, stock) VALUES
  ('Reusable Tote Bag', 'Eco-friendly canvas tote bag.', 'Lifestyle', 80, 50),
  ('Bamboo Water Bottle', 'Insulated bamboo-finish water bottle.', 'Lifestyle', 120, 30),
  ('Campus Cafe Voucher', '10% off at the campus cafe.', 'Campus', 60, 100),
  ('Stationery Set', 'Recycled-paper notebook and pens.', 'Campus', 90, 40),
  ('Seed Starter Kit', 'Grow your own herbs at home.', 'Eco Essentials', 150, 20),
  ('Solar Phone Charger', 'Pocket-sized solar charging panel.', 'Eco Essentials', 300, 10);

-- Default Admin account
-- Username: admin
-- Email: admin@ecotrack.com
-- Password: admin1234
INSERT INTO users (username, email, password, role) VALUES
  ('admin', 'admin@ecotrack.com',
   '$2y$10$TFwqkDalLGsVc2eUl2UoQ.e6zFYiiqfmlECUA.Fl5J.9.Ax.iGpga',
   'admin');

-- Default Moderator account
-- Username: moderator
-- Email: mod@ecotrack.com
-- Password: mod123
INSERT INTO users (username, email, password, role) VALUES
  ('moderator', 'mod@ecotrack.com',
   '$2y$10$43jcNbWQAmePyIJv5sKet.pXb8nt.xaRV8LPsJ.bU8547DCTXApP6',
   'moderator');
