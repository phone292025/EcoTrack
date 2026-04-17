<?php require_once __DIR__ . '/paths.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Access Denied | EcoTrack</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<main id="mainContent" style="text-align:center;padding:5rem 1rem;">
  <div style="font-size:4rem;">🚫</div>
  <h1 style="color:var(--clr-danger);margin:1rem 0;">Access Denied</h1>
  <p style="color:var(--clr-text-muted);margin-bottom:2rem;">
    You do not have permission to view this page.
  </p>
  <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">Go Home</a>
</main>
</body>
</html>
