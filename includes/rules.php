<?php
/**
 * EcoTrack — Game rules and fixed settings.
 *
 * Every number that decides how many points something is worth, and every
 * limit a form enforces, lives here so the pages, the hints they show and the
 * server-side checks all read the same value.
 */

/* Points --------------------------------------------------------------- */
const POINTS_PER_ACTIVITY  = 10;
const POINTS_DAILY_CHECKIN = 5;
const POINTS_GOAL_BONUS    = 25;

/** Streak length in days => bonus points, paid once per streak run. */
const STREAK_BONUSES = [3 => 5, 7 => 15, 30 => 50];

/* Points ledger -------------------------------------------------------- */

/**
 * What kind of movement a points_transactions row records. Reports and
 * bonus rules filter on this instead of pattern-matching the reason text.
 */
const LEDGER_KINDS = [
    'activity',      // an approved activity log
    'checkin',       // the daily check-in
    'streak_bonus',  // a streak milestone
    'goal_bonus',    // a personal goal was met
    'challenge',     // a challenge was completed
    'redemption',    // points spent in the Green Shop
    'refund',        // a cancelled redemption paid back
    'adjustment',    // anything else, e.g. a legacy row
];

/**
 * Kinds that count as earning toward a personal goal. The goal bonus itself
 * and refunds are deliberately left out: counting the bonus toward the next
 * goal is what let a re-saved goal pay out again and again.
 */
const GOAL_PROGRESS_KINDS = ['activity', 'checkin', 'streak_bonus', 'challenge'];

/* Limits --------------------------------------------------------------- */
const GOAL_TARGET_MIN          = 10;
const GOAL_TARGET_MAX          = 100000;
const ACTIVITY_DESCRIPTION_MIN = 10;
const ACTIVITY_DESCRIPTION_MAX = 2000;
const MAX_PENDING_SUBMISSIONS  = 10;    // per participant, keeps the review queue honest
const REVIEW_NOTE_MAX          = 255;
const UPLOAD_MAX_BYTES         = 5 * 1024 * 1024;

const USERNAME_MIN = 3;
const USERNAME_MAX = 50;
const EMAIL_MAX    = 100;
const PASSWORD_MIN = 8;

const TITLE_MAX = 200;   // eco tips and announcements
const BODY_MAX  = 5000;  // long free text: tip, announcement and challenge bodies

const CHALLENGE_TITLE_MAX  = 150;
const CHALLENGE_POINTS_MAX = 9999;
const CHALLENGE_TARGET_MAX = 365;

const REWARD_NAME_MAX   = 150;
const REWARD_COST_MAX   = 100000;
const REWARD_STOCK_MAX  = 100000;
const REWARD_CATEGORIES = ['Lifestyle', 'Campus', 'Eco Essentials'];

const BADGE_NAME_MAX = 100;

/* Demo accounts -------------------------------------------------------- */

/**
 * The admin and moderator accounts seeded by database/ecotrack.sql. The login
 * page shows these while DEMO_MODE is on, and scripts/check_login_users.php
 * confirms the seeded hashes still match them.
 */
const DEMO_ACCOUNTS = [
    ['username' => 'admin',     'email' => 'admin@ecotrack.com', 'password' => 'EcoAdmin2026', 'role' => 'admin'],
    ['username' => 'moderator', 'email' => 'mod@ecotrack.com',   'password' => 'EcoMod2026',   'role' => 'moderator'],
];
