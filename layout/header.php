<?php
/**
 * EcoTrack — Shared Page Header
 * File: layout/header.php
 *
 * Variables expected before include:
 *   $pageTitle   (string)  — shown in <title> and page heading area
 *   $needsCharts (bool)    — set true on pages that draw Chart.js charts
 */
require_once __DIR__ . '/../includes/paths.php';
$pageTitle   = $pageTitle ?? 'EcoTrack';
$needsCharts = $needsCharts ?? false;
$role        = $_SESSION['role'] ?? 'guest';

// Points come from the session, refreshed whenever the balance changes, so
// the shared layout does not run a query on every page in the project.
$navPoints = function_exists('currentPoints') ? currentPoints() : 0;

$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');

/** Mark the nav link for the page being viewed. */
function navLink(string $href, string $label, string $currentScript): string
{
    $isActive = basename($href) === $currentScript;

    return sprintf(
        '<a href="%s"%s>%s</a>',
        htmlspecialchars($href, ENT_QUOTES, 'UTF-8'),
        $isActive ? ' class="nav-link--active" aria-current="page"' : '',
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="EcoTrack — Sustainable Activity Tracking & Rewards">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | EcoTrack</title>
  <link rel="icon" href="<?= BASE_URL ?>/assets/img/logo.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
  <?php if ($needsCharts): ?>
    <!-- Chart.js is vendored locally so charts still work with no internet
         connection, and so no third party can change what this page runs. -->
    <script src="<?= BASE_URL ?>/assets/js/vendor/chart.umd.min.js" defer></script>
  <?php endif; ?>
</head>
<body>

<a href="#mainContent" class="skip-link">Skip to main content</a>

<!-- ═══════════════════════════════════════════════════════ -->
<!--  TOP NAVIGATION BAR                                     -->
<!-- ═══════════════════════════════════════════════════════ -->
<header class="navbar" role="banner">
  <div class="nav-container">

    <!-- Logo (team-created SVG) -->
    <a href="<?= BASE_URL ?>/index.php" class="nav-logo" aria-label="EcoTrack Home">
      <img src="<?= BASE_URL ?>/assets/img/logo.svg" alt="EcoTrack leaf logo" width="36" height="36">
      <span class="logo-text">Eco<strong>Track</strong></span>
    </a>

    <!-- Hamburger toggle (mobile) -->
    <button class="hamburger" id="hamburgerBtn" aria-label="Toggle navigation menu"
            aria-expanded="false" aria-controls="navMenu">
      <span></span><span></span><span></span>
    </button>

    <!-- Navigation links — change by role -->
    <nav class="nav-menu" id="navMenu" role="navigation" aria-label="Main navigation">
      <?php if ($role === 'participant'): ?>
        <?= navLink(BASE_URL . '/participant/dashboard.php',    'Dashboard',   $currentScript) ?>
        <?= navLink(BASE_URL . '/participant/log_activity.php', 'Log Activity', $currentScript) ?>
        <?= navLink(BASE_URL . '/participant/challenges.php',   'Challenges',  $currentScript) ?>
        <?= navLink(BASE_URL . '/participant/shop.php',         'Green Shop',  $currentScript) ?>
        <?= navLink(BASE_URL . '/participant/points.php',       'Points',      $currentScript) ?>
        <?= navLink(BASE_URL . '/participant/leaderboard.php',  'Leaderboard', $currentScript) ?>
        <?= navLink(BASE_URL . '/participant/profile.php',      'Profile',     $currentScript) ?>

      <?php elseif ($role === 'moderator'): ?>
        <?= navLink(BASE_URL . '/moderator/dashboard.php',           'Dashboard',    $currentScript) ?>
        <?= navLink(BASE_URL . '/moderator/review_submissions.php',  'Review',       $currentScript) ?>
        <?= navLink(BASE_URL . '/moderator/participant_table.php',   'Participants', $currentScript) ?>
        <?= navLink(BASE_URL . '/moderator/create_challenge.php',    'Challenges',   $currentScript) ?>
        <?= navLink(BASE_URL . '/moderator/eco_tips.php',            'Eco Tips',     $currentScript) ?>

      <?php elseif ($role === 'admin'): ?>
        <?= navLink(BASE_URL . '/admin/dashboard.php',             'Dashboard',     $currentScript) ?>
        <?= navLink(BASE_URL . '/admin/review_submissions.php',    'Review',        $currentScript) ?>
        <?= navLink(BASE_URL . '/admin/user_management.php',       'Users',         $currentScript) ?>
        <?= navLink(BASE_URL . '/admin/participant_table.php',     'Participants',  $currentScript) ?>
        <?= navLink(BASE_URL . '/admin/challenge_management.php',  'Challenges',    $currentScript) ?>
        <?= navLink(BASE_URL . '/admin/eco_tips.php',              'Eco Tips',      $currentScript) ?>
        <?= navLink(BASE_URL . '/admin/rewards_management.php',    'Rewards',       $currentScript) ?>
        <?= navLink(BASE_URL . '/admin/badges_management.php',     'Badges',        $currentScript) ?>
        <?= navLink(BASE_URL . '/admin/announcements.php',         'Announcements', $currentScript) ?>

      <?php else: ?>
        <?= navLink(BASE_URL . '/login.php',    'Login',    $currentScript) ?>
        <?= navLink(BASE_URL . '/register.php', 'Register', $currentScript) ?>
      <?php endif; ?>

      <?php if (isset($_SESSION['user_id'])): ?>
        <a href="<?= BASE_URL ?>/logout.php" class="nav-logout">Logout</a>
      <?php endif; ?>
    </nav>

    <!-- Points badge (participants only — moderators and admins do not earn) -->
    <?php if (isset($_SESSION['user_id']) && $role === 'participant'): ?>
      <div class="nav-points" aria-label="Your points balance">
        <img src="<?= BASE_URL ?>/assets/img/icon_leaf.svg" alt="" width="16" height="16">
        <span id="navPointsBadge"><?= (int)$navPoints ?> pts</span>
      </div>
    <?php endif; ?>

  </div>
</header>

<main id="mainContent">
