<?php
/**
 * EcoTrack — Schema Migration
 * File: scripts/migrate.php
 *
 * CLI only:  php scripts/migrate.php
 *
 * Brings an existing database up to the current schema. Safe to run more than
 * once. This used to run on every page request from database/db.php, which
 * meant the running application could alter its own schema — it now happens
 * here, once, during setup.
 *
 * For a brand new database, import database/ecotrack.sql first.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../database/db.php';

$pdo = getPDO();

echo "EcoTrack schema migration\n";
echo "-------------------------\n";

$changes = 0;

function step(string $label, callable $fn): void
{
    global $changes;

    try {
        $didWork = $fn();
        if ($didWork) {
            $changes++;
            echo "  [applied] {$label}\n";
        } else {
            echo "  [ok]      {$label}\n";
        }
    } catch (Throwable $e) {
        echo "  [FAILED]  {$label}: " . $e->getMessage() . "\n";
    }
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

/* ------------------------------------------------------------------
 * Legacy column renames (older builds of this project)
 * ----------------------------------------------------------------*/

step('users.user_id primary key', function () use ($pdo) {
    if (!tableExists($pdo, 'users')) {
        return false;
    }
    if (columnExists($pdo, 'users', 'id') && !columnExists($pdo, 'users', 'user_id')) {
        $pdo->exec('ALTER TABLE users CHANGE COLUMN id user_id INT(11) NOT NULL AUTO_INCREMENT');
        return true;
    }
    return false;
});

$userColumns = [
    'password'     => "ALTER TABLE users ADD COLUMN password VARCHAR(255) NULL AFTER email",
    'role'         => "ALTER TABLE users ADD COLUMN role ENUM('participant','moderator','admin') NOT NULL DEFAULT 'participant' AFTER password",
    'points'       => 'ALTER TABLE users ADD COLUMN points INT NOT NULL DEFAULT 0 AFTER role',
    'streak'       => 'ALTER TABLE users ADD COLUMN streak INT NOT NULL DEFAULT 0 AFTER points',
    'last_checkin' => 'ALTER TABLE users ADD COLUMN last_checkin DATE DEFAULT NULL AFTER streak',
    'avatar'       => 'ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL AFTER last_checkin',
];

foreach ($userColumns as $column => $sql) {
    step("users.{$column}", function () use ($pdo, $column, $sql) {
        if (!tableExists($pdo, 'users') || columnExists($pdo, 'users', $column)) {
            return false;
        }
        $pdo->exec($sql);
        return true;
    });
}

step('users role backfill from role_id', function () use ($pdo) {
    if (!tableExists($pdo, 'users') || !columnExists($pdo, 'users', 'role_id')) {
        return false;
    }

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
    return true;
});

step('users role sanity', function () use ($pdo) {
    if (!tableExists($pdo, 'users')) {
        return false;
    }
    $stmt = $pdo->query(
        "UPDATE users
         SET role = 'participant'
         WHERE role IS NULL OR role NOT IN ('participant', 'moderator', 'admin')"
    );
    return $stmt->rowCount() > 0;
});

/* ------------------------------------------------------------------
 * New columns introduced by the current release
 * ----------------------------------------------------------------*/

step('activity_logs.review_note', function () use ($pdo) {
    if (!tableExists($pdo, 'activity_logs') || columnExists($pdo, 'activity_logs', 'review_note')) {
        return false;
    }
    $pdo->exec('ALTER TABLE activity_logs ADD COLUMN review_note VARCHAR(255) DEFAULT NULL AFTER status');
    return true;
});

step('challenges.target_count', function () use ($pdo) {
    if (!tableExists($pdo, 'challenges') || columnExists($pdo, 'challenges', 'target_count')) {
        return false;
    }
    $pdo->exec('ALTER TABLE challenges ADD COLUMN target_count INT NOT NULL DEFAULT 1 AFTER points');
    return true;
});

step('login_attempts table', function () use ($pdo) {
    if (tableExists($pdo, 'login_attempts')) {
        return false;
    }
    $pdo->exec(
        'CREATE TABLE login_attempts (
            attempt_id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(100) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_identifier (identifier, attempted_at),
            INDEX idx_login_ip (ip_address, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    return true;
});

/* ------------------------------------------------------------------
 * Points ledger consistency
 * ----------------------------------------------------------------*/

step('points_transactions.txn_id primary key', function () use ($pdo) {
    if (!tableExists($pdo, 'points_transactions')) {
        return false;
    }
    if (columnExists($pdo, 'points_transactions', 'id') && !columnExists($pdo, 'points_transactions', 'txn_id')) {
        $pdo->exec('ALTER TABLE points_transactions CHANGE COLUMN id txn_id INT(11) NOT NULL AUTO_INCREMENT');
        return true;
    }
    return false;
});

$txnColumns = [
    'delta'  => 'ALTER TABLE points_transactions ADD COLUMN delta INT DEFAULT NULL AFTER user_id',
    'reason' => 'ALTER TABLE points_transactions ADD COLUMN reason VARCHAR(255) DEFAULT NULL AFTER delta',
    'ref_id' => 'ALTER TABLE points_transactions ADD COLUMN ref_id INT DEFAULT NULL AFTER reason',
];

foreach ($txnColumns as $column => $sql) {
    step("points_transactions.{$column}", function () use ($pdo, $column, $sql) {
        if (!tableExists($pdo, 'points_transactions') || columnExists($pdo, 'points_transactions', $column)) {
            return false;
        }
        $pdo->exec($sql);
        return true;
    });
}

step('points_transactions legacy delta backfill', function () use ($pdo) {
    if (!tableExists($pdo, 'points_transactions')) {
        return false;
    }
    if (!columnExists($pdo, 'points_transactions', 'points')
        || !columnExists($pdo, 'points_transactions', 'transaction_type')) {
        return false;
    }

    $pdo->exec(
        "UPDATE points_transactions
         SET delta = CASE
             WHEN delta IS NOT NULL THEN delta
             WHEN transaction_type IN ('redeemed', 'deducted') THEN -ABS(points)
             ELSE ABS(points)
         END"
    );
    return true;
});

/* ------------------------------------------------------------------
 * Reconcile balances against the ledger
 * ----------------------------------------------------------------*/

step('users.points reconciled with ledger', function () use ($pdo) {
    if (!tableExists($pdo, 'users') || !tableExists($pdo, 'points_transactions')) {
        return false;
    }

    $stmt = $pdo->query(
        'UPDATE users u
         JOIN (
             SELECT user_id, COALESCE(SUM(delta), 0) AS total
             FROM points_transactions
             GROUP BY user_id
         ) t ON t.user_id = u.user_id
         SET u.points = GREATEST(0, t.total)
         WHERE u.points <> GREATEST(0, t.total)'
    );

    $fixed = $stmt->rowCount();
    if ($fixed > 0) {
        echo "            reconciled {$fixed} user balance(s)\n";
    }
    return $fixed > 0;
});

/* ------------------------------------------------------------------
 * Seed data for a database that has tables but no reference rows
 * ----------------------------------------------------------------*/

step('categories seed', function () use ($pdo) {
    if (!tableExists($pdo, 'categories')) {
        return false;
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() > 0) {
        return false;
    }
    $pdo->exec(
        "INSERT INTO categories (name, icon, co2_per_point) VALUES
         ('Recycling', 'icon_recycling.svg', 0.0100),
         ('Plastic Reduction', 'icon_plastic.svg', 0.0150),
         ('Energy Saving', 'icon_energy.svg', 0.0200),
         ('Green Transport', 'icon_transport.svg', 0.0250)"
    );
    return true;
});

step('badges seed', function () use ($pdo) {
    if (!tableExists($pdo, 'badges')) {
        return false;
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM badges')->fetchColumn() > 0) {
        return false;
    }
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
    return true;
});

step('badge icon filenames', function () use ($pdo) {
    if (!tableExists($pdo, 'badges')) {
        return false;
    }

    $icons = [
        'logs>=1'      => 'badge_firstlog.svg',
        'points>=50'   => 'badge_50pts.svg',
        'points>=100'  => 'badge_100pts.svg',
        'points>=500'  => 'badge_500pts.svg',
        'streak>=7'    => 'badge_streak7.svg',
        'streak>=30'   => 'badge_streak30.svg',
        'goal_achieved' => 'badge_goal.svg',
    ];

    $stmt = $pdo->prepare(
        "UPDATE badges SET icon = ? WHERE criteria = ? AND (icon IS NULL OR icon = '')"
    );

    $applied = 0;
    foreach ($icons as $criteria => $icon) {
        $stmt->execute([$icon, $criteria]);
        $applied += $stmt->rowCount();
    }

    return $applied > 0;
});

step('rewards seed', function () use ($pdo) {
    if (!tableExists($pdo, 'rewards')) {
        return false;
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM rewards')->fetchColumn() > 0) {
        return false;
    }
    $pdo->exec(
        "INSERT INTO rewards (name, description, category, point_cost, stock) VALUES
         ('Reusable Tote Bag', 'Eco-friendly canvas tote bag.', 'Lifestyle', 80, 50),
         ('Bamboo Water Bottle', 'Insulated bamboo-finish water bottle.', 'Lifestyle', 120, 30),
         ('Campus Cafe Voucher', '10% off at the campus cafe.', 'Campus', 60, 100),
         ('Stationery Set', 'Recycled-paper notebook and pens.', 'Campus', 90, 40),
         ('Seed Starter Kit', 'Grow your own herbs at home.', 'Eco Essentials', 150, 20),
         ('Solar Phone Charger', 'Pocket-sized solar charging panel.', 'Eco Essentials', 300, 10)"
    );
    return true;
});

echo "-------------------------\n";
echo $changes === 0
    ? "Schema already up to date.\n"
    : "Migration complete. {$changes} change(s) applied.\n";
