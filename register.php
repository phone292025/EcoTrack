<?php
/**
 * EcoTrack — Registration Page
 * File: register.php
 */
require_once __DIR__ . '/includes/bootstrap.php';

if (isLoggedIn()) redirectByRole();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf($_POST['csrf'] ?? '');

    $username = trim((string)($_POST['username'] ?? ''));
    $email    = trim((string)($_POST['email']    ?? ''));
    $password = (string)($_POST['password']         ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');

    // Server-side validation (mirrors the JS checks, which are only a convenience)
    $errors = array_values(array_filter([
        usernameProblem($username),
        emailProblem($email),
        passwordProblem($password),
        $password !== $confirm ? 'Passwords do not match.' : null,
    ]));

    // Check uniqueness up front for a friendly message. The UNIQUE constraints
    // on the table are what actually guarantee it — two simultaneous requests
    // can both pass this check, and the insert below handles that case.
    if (empty($errors)) {
        $stmt = getPDO()->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$username, $email]);
        if ((int)$stmt->fetchColumn() > 0)
            $errors[] = 'That username or email is already registered.';
    }

    if (empty($errors)) {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            getPDO()->prepare(
                'INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, "participant")'
            )->execute([$username, $email, $hash]);

            setFlash('success', 'Account created. You can log in now.');
            redirectTo('/login.php');
        } catch (PDOException $e) {
            if (isDuplicateKeyError($e)) {
                $errors[] = 'That username or email is already registered.';
            } else {
                error_log('[EcoTrack register] ' . $e->getMessage());
                $errors[] = 'Could not create the account right now. Please try again.';
            }
        }
    }

    foreach ($errors as $message) {
        setFlash('error', $message);
    }
    setFormOld(['username' => $username, 'email' => $email]);
    redirectTo('/register.php');
}

$flash = takeFlash();
$old = takeFormOld();
$pageTitle = 'Register';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register | EcoTrack</title>
  <link rel="icon" href="<?= BASE_URL ?>/assets/img/logo.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<main id="mainContent">
<div class="auth-wrap">
  <div class="card">

    <div class="auth-head">
      <img src="<?= BASE_URL ?>/assets/img/logo.svg" alt="EcoTrack" width="56" height="56">
      <h1 class="auth-title">Join EcoTrack</h1>
      <p class="auth-subtitle">Start tracking your eco-friendly activities today</p>
    </div>

    <?php renderFlash($flash); ?>

    <form method="POST" action="<?= BASE_URL ?>/register.php" data-validate="register" novalidate>
      <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">

      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username"
               value="<?= sanitise($old['username'] ?? '') ?>"
               autocomplete="username" maxlength="50" required>
        <small class="field-hint">3-50 characters. Letters, numbers and underscores only.</small>
      </div>

      <div class="form-group">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email"
               value="<?= sanitise($old['email'] ?? '') ?>"
               autocomplete="email" required>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               autocomplete="new-password" required>
        <small class="field-hint">
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

    <p class="auth-footer">
      Already have an account?
      <a href="<?= BASE_URL ?>/login.php">Log in</a>
    </p>

  </div>
</div>
</main>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
