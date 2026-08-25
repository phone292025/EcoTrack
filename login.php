<?php
/**
 * EcoTrack — Login Page
 * File: login.php
 */
require_once __DIR__ . '/database/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/paths.php';

// Already logged in — go to dashboard
if (isLoggedIn()) redirectByRole();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');

    $identifier = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$identifier || !$password) {
        setFlash('error', 'Please enter your email or username and password.');
        setFormOld(['email' => $identifier]);
        redirectTo('/login.php');
    }

    if (isLoginLocked($identifier)) {
        setFlash('error', sprintf(
            'Too many failed attempts. Please wait %d minutes and try again.',
            LOGIN_LOCKOUT_MINUTES
        ));
        setFormOld(['email' => $identifier]);
        redirectTo('/login.php');
    }

    $stmt = getPDO()->prepare(
        'SELECT user_id, username, email, password, role, points
         FROM users
         WHERE email = ? OR username = ?
         LIMIT 1'
    );
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    // Verify against a dummy hash when the account does not exist so that a
    // wrong username and a wrong password take the same amount of time. Timing
    // differences otherwise reveal which usernames are real.
    $hash = ($user && is_string($user['password']) && $user['password'] !== '')
        ? $user['password']
        : '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    if (password_verify($password, $hash) && $user) {
        clearLoginFailures($identifier);
        loginUser($user);
        redirectByRole();
    }

    recordLoginFailure($identifier);

    $remaining = max(0, LOGIN_MAX_ATTEMPTS - loginFailureCount($identifier));
    $message = 'Incorrect email/username or password. Please try again.';
    if ($remaining > 0 && $remaining <= 2) {
        $message .= sprintf(' %d attempt%s left before a temporary lockout.', $remaining, $remaining === 1 ? '' : 's');
    }

    setFlash('error', $message);
    setFormOld(['email' => $identifier]);
    redirectTo('/login.php');
}

$flash = takeFlash();
$old = takeFormOld();
$pageTitle = 'Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login | EcoTrack</title>
  <link rel="icon" href="<?= BASE_URL ?>/assets/img/logo.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<main id="mainContent">
<div class="auth-wrap">
  <div class="card">

    <!-- Logo -->
    <div class="auth-head">
      <img src="<?= BASE_URL ?>/assets/img/logo.svg" alt="EcoTrack" width="56" height="56">
      <h1 class="auth-title">Welcome Back</h1>
      <p class="auth-subtitle">Log in to continue your eco journey</p>
    </div>

    <?php foreach ($flash['error'] as $message): ?>
      <div class="flash-message flash-error" role="alert"><?= sanitise($message) ?></div>
    <?php endforeach; ?>
    <?php foreach ($flash['success'] as $message): ?>
      <div class="flash-message flash-success" role="status"><?= sanitise($message) ?></div>
    <?php endforeach; ?>

    <form method="POST" action="<?= BASE_URL ?>/login.php" data-validate="login" novalidate>
      <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">

      <div class="form-group">
        <label for="email">Email Address or Username</label>
        <input type="text" id="email" name="email"
               value="<?= sanitise($old['email'] ?? '') ?>"
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

    <?php if (DEMO_MODE): ?>
      <!-- Marking/demo convenience only. Set DEMO_MODE to false in
           database/db.local.php for any deployment that is not a demo. -->
      <div class="demo-credentials">
        <div>
          <strong>Demo admin login</strong>
          <span>Username: <code>admin</code></span>
          <span>Email: <code>admin@ecotrack.com</code></span>
          <span>Password: <code>EcoAdmin2026</code></span>
        </div>
        <div>
          <strong>Demo moderator login</strong>
          <span>Username: <code>moderator</code></span>
          <span>Email: <code>mod@ecotrack.com</code></span>
          <span>Password: <code>EcoMod2026</code></span>
        </div>
      </div>
    <?php endif; ?>

    <p class="auth-footer">
      Don't have an account?
      <a href="<?= BASE_URL ?>/register.php">Register here</a>
    </p>

  </div>
</div>
</main>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
