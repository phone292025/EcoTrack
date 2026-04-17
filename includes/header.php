<?php
/**
 * EcoTrack — Shared Page Header
 * File: includes/header.php
 *
 * Variables expected before include:
 *   $pageTitle  (string)  — shown in <title> and page heading area
 */
require_once __DIR__ . '/paths.php';
$pageTitle = $pageTitle ?? 'EcoTrack';
$role      = $_SESSION['role'] ?? 'guest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="EcoTrack — Sustainable Activity Tracking & Rewards">
  <title><?= htmlspecialchars($pageTitle) ?> | EcoTrack</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
  <!-- Chart.js (loaded only where needed via defer) -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
</head>
<body>

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
        <a href="<?= BASE_URL ?>/participant/dashboard.php">Dashboard</a>
        <a href="<?= BASE_URL ?>/participant/log_activity.php">Log Activity</a>
        <a href="<?= BASE_URL ?>/participant/challenges.php">Challenges</a>
        <a href="<?= BASE_URL ?>/participant/shop.php">Green Shop</a>
        <a href="<?= BASE_URL ?>/participant/points.php">Points</a>
        <a href="<?= BASE_URL ?>/participant/leaderboard.php">Leaderboard</a>
        <a href="<?= BASE_URL ?>/participant/profile.php">Profile</a>

      <?php elseif ($role === 'moderator'): ?>
        <a href="<?= BASE_URL ?>/moderator/dashboard.php">Dashboard</a>
        <a href="<?= BASE_URL ?>/moderator/review_submissions.php">Review</a>
        <a href="<?= BASE_URL ?>/moderator/participant_table.php">Participants</a>
        <a href="<?= BASE_URL ?>/moderator/create_challenge.php">Challenges</a>
        <a href="<?= BASE_URL ?>/moderator/eco_tips.php">Eco Tips</a>

      <?php elseif ($role === 'admin'): ?>
        <a href="<?= BASE_URL ?>/admin/dashboard.php">Dashboard</a>
        <a href="<?= BASE_URL ?>/admin/review_submissions.php">Review</a>
        <a href="<?= BASE_URL ?>/admin/user_management.php">Users</a>
        <a href="<?= BASE_URL ?>/admin/participant_table.php">Participants</a>
        <a href="<?= BASE_URL ?>/admin/challenge_management.php">Challenges</a>
        <a href="<?= BASE_URL ?>/admin/eco_tips.php">Eco Tips</a>
        <a href="<?= BASE_URL ?>/admin/rewards_management.php">Rewards</a>
        <a href="<?= BASE_URL ?>/admin/badges_management.php">Badges</a>
        <a href="<?= BASE_URL ?>/admin/announcements.php">Announcements</a>

      <?php else: ?>
        <a href="<?= BASE_URL ?>/login.php">Login</a>
        <a href="<?= BASE_URL ?>/register.php">Register</a>
      <?php endif; ?>

      <?php if (isset($_SESSION['user_id'])): ?>
        <a href="<?= BASE_URL ?>/logout.php" class="nav-logout">Logout</a>
      <?php endif; ?>
    </nav>

    <!-- Points badge (logged-in users) -->
    <?php if (isset($_SESSION['user_id'])): ?>
      <?php
        $u = getPDO()->prepare('SELECT points FROM users WHERE user_id = ?');
        $u->execute([$_SESSION['user_id']]);
        $pts = (int)($u->fetchColumn() ?: 0);
      ?>
      <div class="nav-points" aria-label="Your points balance">
        <img src="<?= BASE_URL ?>/assets/img/icon_leaf.svg" alt="" width="16" height="16">
        <span id="navPointsBadge"><?= $pts ?> pts</span>
      </div>
    <?php endif; ?>

  </div>
</header>

<main id="mainContent">
