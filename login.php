<?php
/**
 * EcoTrack — Login Page
 * File: login.php
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/paths.php';

// Already logged in — go to dashboard
if (isLoggedIn()) redirectByRole();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');

    $identifier = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$identifier || !$password) {
        $error = 'Please enter your email or username and password.';
    } else {
        $stmt = getPDO()->prepare(
            'SELECT user_id, username, email, password, role
             FROM users
             WHERE email = ? OR username = ?
             LIMIT 1'
        );
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            loginUser($user);
            redirectByRole();
        } else {
            $error = 'Incorrect email/username or password. Please try again.';
        }
    }
}

$pageTitle = 'Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login | EcoTrack</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<main id="mainContent">
<div class="auth-wrap">
  <div class="card">

    <!-- Logo -->
    <div style="text-align:center;margin-bottom:1.5rem;">
      <img src="<?= BASE_URL ?>/assets/img/logo.svg" alt="EcoTrack" width="56" height="56"
           style="margin:0 auto 0.5rem;">
      <h1 class="auth-title">Welcome Back</h1>
      <p style="color:var(--clr-text-muted);font-size:0.9rem;">
        Log in to continue your eco journey 🌿
      </p>
    </div>

    <?php if ($error): ?>
      <div class="flash-message flash-error" role="alert"><?= sanitise($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= BASE_URL ?>/login.php" data-validate="login" novalidate>
      <input type="hidden" name="csrf" value="<?= csrfToken() ?>">

      <div class="form-group">
        <label for="email">Email Address or Username</label>
        <input type="text" id="email" name="email"
               value="<?= sanitise($_POST['email'] ?? '') ?>"
               autocomplete="username" required>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               autocomplete="current-password" required>
      </div>

      <button type="submit" class="btn btn-primary btn-block btn-lg">
        Log In
      </button>
    </form>
    <div style="margin-top:1.25rem;padding:1rem;border:1px solid var(--clr-border);border-radius:var(--radius-md);background:#f8fbf9;display:grid;gap:0.75rem;">
      <div>
        <strong style="display:block;margin-bottom:0.25rem;">Demo admin login</strong>
        <span style="display:block;color:var(--clr-text-muted);font-size:0.9rem;">Username: <code>admin</code></span>
        <span style="display:block;color:var(--clr-text-muted);font-size:0.9rem;">Email: <code>admin@ecotrack.com</code></span>
        <span style="display:block;color:var(--clr-text-muted);font-size:0.9rem;">Password: <code>admin1234</code></span>
      </div>
      <div>
        <strong style="display:block;margin-bottom:0.25rem;">Demo moderator login</strong>
        <span style="display:block;color:var(--clr-text-muted);font-size:0.9rem;">Username: <code>moderator</code></span>
        <span style="display:block;color:var(--clr-text-muted);font-size:0.9rem;">Email: <code>mod@ecotrack.com</code></span>
        <span style="display:block;color:var(--clr-text-muted);font-size:0.9rem;">Password: <code>mod123</code></span>
      </div>
    </div>

    <p style="text-align:center;margin-top:1.5rem;font-size:0.9rem;color:var(--clr-text-muted);">
      Don't have an account?
      <a href="<?= BASE_URL ?>/register.php" style="font-weight:600;">Register here</a>
    </p>

  </div>
</div>
</main>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
