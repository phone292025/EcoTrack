<?php
/**
 * EcoTrack — Core business logic.
 * File: includes/functions.php
 *
 * Loads every domain module. Each module has one job:
 *
 *   rules.php       points values, limits and demo accounts
 *   helpers.php     escaping, JSON, the database clock, input checks
 *   ledger.php      points, streaks, daily check-in, personal goals
 *   badges.php      the badge engine and manual awards
 *   challenges.php  joining, progress and completion
 *   activities.php  submitting activity and moderating it
 *   rewards.php     the Green Shop and redemption fulfilment
 *   reports.php     read-only queries for dashboards and charts
 *   uploads.php     image upload handling
 *
 * Reading vs writing: get*() functions only read. Anything that awards points,
 * badges or challenge completions is a write, is named for what it does
 * (award, apply, save, redeem, review...), and is only ever called from a
 * POST handler — never from a page render.
 */

require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ledger.php';
require_once __DIR__ . '/badges.php';
require_once __DIR__ . '/challenges.php';
require_once __DIR__ . '/activities.php';
require_once __DIR__ . '/rewards.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/uploads.php';
