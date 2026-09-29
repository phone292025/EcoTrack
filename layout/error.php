<?php
/**
 * EcoTrack — Shown when a request fails unexpectedly. The details go to the
 * server's error log; $debugMessage is only filled in when APP_DEBUG is on.
 */
require_once __DIR__ . '/../includes/paths.php';
$debugMessage = $debugMessage ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Something went wrong | EcoTrack</title>
  <link rel="icon" href="<?= BASE_URL ?>/assets/img/logo.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<main id="mainContent" class="status-page">
  <h1 class="status-page__title">Something went wrong</h1>
  <p class="status-page__text">
    That request could not be completed. Nothing was saved. Please go back and try again.
  </p>
  <?php if ($debugMessage !== ''): ?>
    <pre class="status-page__debug"><?= htmlspecialchars($debugMessage, ENT_QUOTES, 'UTF-8') ?></pre>
  <?php endif; ?>
  <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">Go home</a>
</main>
</body>
</html>
