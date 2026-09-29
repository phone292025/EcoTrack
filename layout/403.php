<?php require_once __DIR__ . '/../includes/paths.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Access Denied | EcoTrack</title>
  <link rel="icon" href="<?= BASE_URL ?>/assets/img/logo.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<main id="mainContent" class="status-page">
  <div class="status-page__icon" aria-hidden="true">🚫</div>
  <h1 class="status-page__title status-page__title--danger">Access Denied</h1>
  <p class="status-page__text">You do not have permission to view this page.</p>
  <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">Go Home</a>
</main>
</body>
</html>
