<?php
/**
 * EcoTrack — Logout
 * File: logout.php
 *
 * Logging out is a POST with a CSRF token, so another site cannot sign a
 * user out by embedding a link. A plain GET (an old bookmark, say) shows a
 * one-button confirmation instead.
 */
require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');
    logoutUser();
    setFlash('success', 'You have been logged out.');
    redirectTo('/login.php');
}

if (!isLoggedIn()) {
    redirectTo('/login.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Log out | EcoTrack</title>
  <link rel="icon" href="<?= BASE_URL ?>/assets/img/logo.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<main id="mainContent">
<div class="auth-wrap">
  <div class="card">
    <div class="auth-head">
      <img src="<?= BASE_URL ?>/assets/img/logo.svg" alt="EcoTrack" width="56" height="56">
      <h1 class="auth-title">Log out?</h1>
      <p class="auth-subtitle">You are signed in as <?= sanitise(currentUsername()) ?>.</p>
    </div>
    <form method="POST" action="<?= BASE_URL ?>/logout.php">
      <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
      <button type="submit" class="btn btn-primary btn-block btn-lg">Log out</button>
    </form>
    <p class="auth-footer">
      <a href="<?= BASE_URL ?>/index.php">Take me back instead</a>
    </p>
  </div>
</div>
</main>
</body>
</html>
