<?php
/**
 * EcoTrack — Database Connection (PDO Singleton)
 * File: includes/db.php
 *
 * Usage:  $pdo = getPDO();
 *
 * Another laptop:
 *   • Import ecotrack.sql into MySQL/MariaDB.
 *   • Optional: copy includes/db.local.example.php → db.local.php and set DB_PASS / DB_HOST.
 *   • Or set env vars: ECOTRACK_DB_HOST, ECOTRACK_DB_NAME, ECOTRACK_DB_USER, ECOTRACK_DB_PASS.
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
                ensureSchemaCompatibility($pdo);
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
        exit('Database connection failed. Please contact the administrator.');
    }

    return $pdo;
}

function ensureSchemaCompatibility(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    migrateUsersTable($pdo);
    migratePointsTransactionsTable($pdo);
    ensureApplicationTables($pdo);
    seedApplicationData($pdo);
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);

    return (int)$stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);

    return (int)$stmt->fetchColumn() > 0;
}

function migrateUsersTable(PDO $pdo): void
{
    if (!tableExists($pdo, 'users')) {
        return;
    }

    if (columnExists($pdo, 'users', 'id') && !columnExists($pdo, 'users', 'user_id')) {
        $pdo->exec(
            'ALTER TABLE users
             CHANGE COLUMN id user_id INT(11) NOT NULL AUTO_INCREMENT'
        );
    }

    if (!columnExists($pdo, 'users', 'password')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN password VARCHAR(255) NULL AFTER email');
    }
    if (!columnExists($pdo, 'users', 'role')) {
        $pdo->exec(
            "ALTER TABLE users
             ADD COLUMN role ENUM('participant','moderator','admin')
             NOT NULL DEFAULT 'participant' AFTER password"
        );
    }
    if (!columnExists($pdo, 'users', 'points')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN points INT NOT NULL DEFAULT 0 AFTER role');
    }
    if (!columnExists($pdo, 'users', 'streak')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN streak INT NOT NULL DEFAULT 0 AFTER points');
    }
    if (!columnExists($pdo, 'users', 'last_checkin')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN last_checkin DATE DEFAULT NULL AFTER streak');
    }
    if (!columnExists($pdo, 'users', 'avatar')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL AFTER last_checkin');
    }

    if (columnExists($pdo, 'users', 'password_hash')) {
        $pdo->exec(
            "UPDATE users
             SET password = password_hash
             WHERE (password IS NULL OR password = '')
               AND password_hash IS NOT NULL
               AND password_hash <> ''"
        );
    }

    if (columnExists($pdo, 'users', 'role_id')) {
        if (tableExists($pdo, 'roles')) {
            $pdo->exec(
                "UPDATE users u
                 LEFT JOIN roles r ON r.id = u.role_id
                 SET u.role = CASE
                     WHEN LOWER(COALESCE(r.role_name, '')) IN ('admin', 'moderator', 'participant')
                         THEN LOWER(r.role_name)
                     ELSE u.role
                 END"
            );
        } else {
            $pdo->exec(
                "UPDATE users
                 SET role = CASE role_id
                     WHEN 1 THEN 'admin'
                     WHEN 2 THEN 'moderator'
                     ELSE 'participant'
                 END"
            );
        }
    }

    if (columnExists($pdo, 'users', 'total_points')) {
        $pdo->exec(
            'UPDATE users
             SET points = total_points
             WHERE total_points IS NOT NULL AND points = 0'
        );
    }

    if (columnExists($pdo, 'users', 'streak_count')) {
        $pdo->exec(
            'UPDATE users
             SET streak = streak_count
             WHERE streak_count IS NOT NULL AND streak = 0'
        );
    }

    if (tableExists($pdo, 'user_profiles') && columnExists($pdo, 'user_profiles', 'avatar_url')) {
        $pdo->exec(
            "UPDATE users u
             JOIN user_profiles p ON p.user_id = u.user_id
             SET u.avatar = p.avatar_url
             WHERE (u.avatar IS NULL OR u.avatar = '')
               AND p.avatar_url IS NOT NULL
               AND p.avatar_url <> ''"
        );
    }

    $pdo->exec(
        "UPDATE users
         SET role = 'participant'
         WHERE role IS NULL OR role NOT IN ('participant', 'moderator', 'admin')"
    );
}

function migratePointsTransactionsTable(PDO $pdo): void
{
    if (!tableExists($pdo, 'points_transactions')) {
        return;
    }

    if (columnExists($pdo, 'points_transactions', 'id') && !columnExists($pdo, 'points_transactions', 'txn_id')) {
        $pdo->exec(
            'ALTER TABLE points_transactions
             CHANGE COLUMN id txn_id INT(11) NOT NULL AUTO_INCREMENT'
        );
    }

    if (!columnExists($pdo, 'points_transactions', 'delta')) {
        $pdo->exec('ALTER TABLE points_transactions ADD COLUMN delta INT DEFAULT NULL AFTER user_id');
    }
    if (!columnExists($pdo, 'points_transactions', 'reason')) {
        $pdo->exec('ALTER TABLE points_transactions ADD COLUMN reason VARCHAR(255) DEFAULT NULL AFTER delta');
    }
    if (!columnExists($pdo, 'points_transactions', 'ref_id')) {
        $pdo->exec('ALTER TABLE points_transactions ADD COLUMN ref_id INT DEFAULT NULL AFTER reason');
    }

    if (columnExists($pdo, 'points_transactions', 'points') && columnExists($pdo, 'points_transactions', 'transaction_type')) {
        $pdo->exec(
            "UPDATE points_transactions
             SET delta = CASE
                 WHEN delta IS NOT NULL THEN delta
                 WHEN transaction_type IN ('redeemed', 'deducted') THEN -ABS(points)
                 ELSE ABS(points)
             END"
        );
        $pdo->exec(
            "UPDATE points_transactions
             SET points = COALESCE(NULLIF(points, 0), ABS(COALESCE(delta, 0)))"
        );
        $pdo->exec(
            "UPDATE points_transactions
             SET transaction_type = CASE
                 WHEN transaction_type IS NOT NULL AND transaction_type <> '' THEN transaction_type
                 WHEN COALESCE(delta, 0) < 0 THEN 'deducted'
                 WHEN COALESCE(delta, 0) > 0 THEN 'earned'
                 ELSE 'bonus'
             END"
        );
        $pdo->exec(
            "ALTER TABLE points_transactions
             MODIFY COLUMN points INT(11) NOT NULL DEFAULT 0"
        );
        $pdo->exec(
            "ALTER TABLE points_transactions
             MODIFY COLUMN transaction_type
             ENUM('earned','redeemed','bonus','deducted') NOT NULL DEFAULT 'bonus'"
        );
    }

    if (columnExists($pdo, 'points_transactions', 'description')) {
        $pdo->exec(
            "UPDATE points_transactions
             SET reason = description
             WHERE (reason IS NULL OR reason = '')
               AND description IS NOT NULL
               AND description <> ''"
        );
    }

    if (columnExists($pdo, 'points_transactions', 'reference_id')) {
        $pdo->exec(
            'UPDATE points_transactions
             SET ref_id = reference_id
             WHERE ref_id IS NULL AND reference_id IS NOT NULL'
        );
    }
}

function ensureApplicationTables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS categories (
            cat_id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            icon VARCHAR(100) DEFAULT NULL,
            co2_per_point DECIMAL(6,4) DEFAULT 0.0100
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS activity_logs (
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
            INDEX idx_activity_logs_user (user_id),
            INDEX idx_activity_logs_status (status),
            INDEX idx_activity_logs_cat (cat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS challenges (
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
            INDEX idx_challenges_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS challenge_participants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            challenge_id INT NOT NULL,
            user_id INT NOT NULL,
            joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed TINYINT(1) DEFAULT 0,
            completed_at DATETIME DEFAULT NULL,
            UNIQUE KEY uq_cp (challenge_id, user_id),
            INDEX idx_cp_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS badges (
            badge_id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            icon VARCHAR(255) DEFAULT NULL,
            criteria VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_badges (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            badge_id INT NOT NULL,
            earned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ub (user_id, badge_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS goals (
            goal_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            target INT NOT NULL,
            period ENUM('weekly','monthly') DEFAULT 'weekly',
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            bonus_awarded TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_goals_user_dates (user_id, start_date, end_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rewards (
            reward_id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            description TEXT,
            image VARCHAR(255) DEFAULT NULL,
            category ENUM('Lifestyle','Campus','Eco Essentials') DEFAULT 'Lifestyle',
            point_cost INT NOT NULL DEFAULT 50,
            stock INT NOT NULL DEFAULT 0,
            active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS redemptions (
            redemption_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            reward_id INT NOT NULL,
            points_spent INT NOT NULL,
            redeemed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_redemptions_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS daily_checkins (
            checkin_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            checkin_date DATE NOT NULL,
            UNIQUE KEY uq_checkin (user_id, checkin_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS eco_tips (
            tip_id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            body TEXT,
            created_by INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS announcements (
            ann_id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            body TEXT,
            created_by INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function seedApplicationData(PDO $pdo): void
{
    if (tableExists($pdo, 'categories') && (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 0) {
        $pdo->exec(
            "INSERT INTO categories (name, icon, co2_per_point) VALUES
             ('Recycling', 'icon_recycling.svg', 0.0100),
             ('Plastic Reduction', 'icon_plastic.svg', 0.0150),
             ('Energy Saving', 'icon_energy.svg', 0.0200),
             ('Green Transport', 'icon_transport.svg', 0.0250)"
        );
    }

    if (tableExists($pdo, 'badges') && (int)$pdo->query('SELECT COUNT(*) FROM badges')->fetchColumn() === 0) {
        $pdo->exec(
            "INSERT INTO badges (name, description, icon, criteria) VALUES
             ('First Log', 'Logged your very first eco-activity!', 'badge_firstlog.svg', 'logs>=1'),
             ('Green Starter', 'Earned 50 points and started your journey.', 'badge_50pts.svg', 'points>=50'),
             ('Eco Achiever', 'Reached 100 points. Great commitment!', 'badge_100pts.svg', 'points>=100'),
             ('Eco Champion', 'Reached 500 points. You are a champion!', 'badge_500pts.svg', 'points>=500'),
             ('7-Day Streak', 'Logged activities 7 days in a row!', 'badge_streak7.svg', 'streak>=7'),
             ('30-Day Streak', 'Incredible. 30 consecutive days of eco-action!', 'badge_streak30.svg', 'streak>=30'),
             ('Goal Crusher', 'Achieved your personal points goal!', 'badge_goal.svg', 'goal_achieved')"
        );
    }

    if (tableExists($pdo, 'rewards') && (int)$pdo->query('SELECT COUNT(*) FROM rewards')->fetchColumn() === 0) {
        $pdo->exec(
            "INSERT INTO rewards (name, description, category, point_cost, stock) VALUES
             ('Reusable Tote Bag', 'Eco-friendly canvas tote bag.', 'Lifestyle', 80, 50),
             ('Bamboo Water Bottle', 'Insulated bamboo-finish water bottle.', 'Lifestyle', 120, 30),
             ('Campus Cafe Voucher', '10% off at the campus cafe.', 'Campus', 60, 100),
             ('Stationery Set', 'Recycled-paper notebook and pens.', 'Campus', 90, 40),
             ('Seed Starter Kit', 'Grow your own herbs at home.', 'Eco Essentials', 150, 20),
             ('Solar Phone Charger', 'Pocket-sized solar charging panel.', 'Eco Essentials', 300, 10)"
        );
    }
}
