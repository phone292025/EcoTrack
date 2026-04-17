<?php
/**
 * EcoTrack — Registration Page
 * File: register.php
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/paths.php';

if (isLoggedIn()) redirectByRole();

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');

    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']         ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    // Server-side validation (mirrors JS validation)
    if (strlen($username) < 3 || strlen($username) > 50)
        $errors[] = 'Username must be 3–50 characters.';
    elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username))
        $errors[] = 'Username may only contain letters, numbers, and underscores.';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Please enter a valid email address.';

    if (strlen($password) < 8)
        $errors[] = 'Password must be at least 8 characters.';
    elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password))
        $errors[] = 'Password must include at least one uppercase letter and one number.';

    if ($password !== $confirm)
        $errors[] = 'Passwords do not match.';

    // Check uniqueness
    if (empty($errors)) {
        $pdo  = getPDO();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$username, $email]);
        if ((int)$stmt->fetchColumn() > 0)
            $errors[] = 'That username or email is already registered.';
    }

    // Insert
    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = getPDO()->prepare(
            'INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, "participant")'
        );
        $stmt->execute([$username, $email, $hash]);
        $success = true;
    }
}

$pageTitle = 'Register';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register | EcoTrack</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<main id="mainContent">
<div class="auth-wrap">
  <div class="card">

    <div style="text-align:center;margin-bottom:1.5rem;">
      <img src="<?= BASE_URL ?>/assets/img/logo.svg" alt="EcoTrack" width="56" height="56"
           style="margin:0 auto 0.5rem;">
      <h1 class="auth-title">Join EcoTrack</h1>
      <p style="color:var(--clr-text-muted);font-size:0.9rem;">
        Start tracking your eco-friendly activities today 🌱
      </p>
    </div>

    <?php if ($success): ?>
      <div class="flash-message flash-success" role="alert">
        Account created! <a href="<?= BASE_URL ?>/login.php">Log in now</a>.
      </div>

    <?php else: ?>

      <?php foreach ($errors as $e): ?>
        <div class="flash-message flash-error" role="alert"><?= sanitise($e) ?></div>
      <?php endforeach; ?>

      <form method="POST" action="<?= BASE_URL ?>/register.php" data-validate="register" novalidate>
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">

        <div class="form-group">
          <label for="username">Username</label>
          <input type="text" id="username" name="username"
                 value="<?= sanitise($_POST['username'] ?? '') ?>"
                 autocomplete="username" maxlength="50" required>
        </div>

        <div class="form-group">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email"
                 value="<?= sanitise($_POST['email'] ?? '') ?>"
                 autocomplete="email" required>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password"
                 autocomplete="new-password" required>
          <small style="color:var(--clr-text-muted);">
            Min 8 characters, one uppercase letter, one number.
          </small>
        </div>

        <div class="form-group">
          <label for="confirm_password">Confirm Password</label>
          <input type="password" id="confirm_password" name="confirm_password"
                 autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn btn-primary btn-block btn-lg">
          Create Account
        </button>
      </form>

    <?php endif; ?>

    <p style="text-align:center;margin-top:1.5rem;font-size:0.9rem;color:var(--clr-text-muted);">
      Already have an account?
      <a href="<?= BASE_URL ?>/login.php" style="font-weight:600;">Log in</a>
    </p>

  </div>
</div>
</main>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
