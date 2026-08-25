<?php
/**
 * EcoTrack - Landing Page
 * File: index.php
 * Logged-in users go to their dashboard; guests see the landing page.
 *
 * NOTE: this file must stay UTF-8 *without* a BOM. A BOM is sent to the
 * browser as output, which breaks the redirect below.
 */
require_once __DIR__ . '/database/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/paths.php';

if (isLoggedIn()) redirectByRole();

$pageTitle = 'EcoTrack - Sustainable Activity Tracking';

/**
 * Landing page feature icons, drawn inline so they need no extra requests
 * and inherit the surrounding text colour.
 */
$featureIcons = [
    'dashboard' => '<path d="M3 13h8V3H3v10Zm0 8h8v-6H3v6Zm10 0h8V11h-8v10Zm0-18v6h8V3h-8Z"/>',
    'points'    => '<path d="M12 2 9.2 8.6 2 9.2l5.5 4.7L5.8 21 12 17.3 18.2 21l-1.7-7.1L22 9.2l-7.2-.6L12 2Z"/>',
    'challenge' => '<path d="M18 3h3v4a5 5 0 0 1-4.1 4.9A5 5 0 0 1 13 15.9V19h3v2H8v-2h3v-3.1a5 5 0 0 1-3.9-4A5 5 0 0 1 3 7V3h3V1h12v2Zm0 2v4.9A3 3 0 0 0 19 7V5h-1ZM5 5v2a3 3 0 0 0 1 2.2V5H5Z"/>',
    'shop'      => '<path d="M7 4V3a3 3 0 0 1 6 0v1h4l1 16H2L3 4h4Zm2 0h2V3a1 1 0 0 0-2 0v1Z"/>',
    'co2'       => '<path d="M4 19h16v2H4v-2Zm1-4 4.5-5.5 3 3.5L16 8l4 7H5Z"/>',
    'streak'    => '<path d="M12 2s5 5.2 5 9.5A5 5 0 0 1 7 11.5C7 7.2 12 2 12 2Zm0 17a4 4 0 0 0 4-4c0-1.6-1-3.4-2-4.8-.8 1.3-2 2.3-2 2.3s-1.2-1-2-2.3c-1 1.4-2 3.2-2 4.8a4 4 0 0 0 4 4Z"/>',
];

function featureIcon(string $path): string
{
    return '<svg class="feature-icon" viewBox="0 0 24 24" width="40" height="40" '
         . 'fill="currentColor" aria-hidden="true" focusable="false">' . $path . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="EcoTrack helps you track eco-friendly activities, earn green points, and make a real environmental impact.">
  <title><?= sanitise($pageTitle) ?></title>
  <link rel="icon" href="<?= BASE_URL ?>/assets/img/logo.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
  <style>
    /* Landing-page only styles */
    .hero {
      background: linear-gradient(135deg, #1e6b4e 0%, #2d936c 50%, #3db883 100%);
      color: #fff;
      text-align: center;
      padding: 5rem 1.5rem 4rem;
      border-radius: var(--radius-lg);
      margin-bottom: 3rem;
    }
    .hero h1 { font-size: clamp(2rem, 5vw, 3.5rem); font-weight: 800; margin-bottom: 1rem; }
    .hero p  { font-size: clamp(1rem, 2.5vw, 1.3rem); opacity: 0.9; max-width: 600px; margin: 0 auto 2rem; }
    .hero-btns { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
    .btn-white { background:#fff; color:var(--clr-primary); font-weight:700; padding:0.75em 2.2em; border-radius:var(--radius-full); font-size:1rem; transition:transform 0.2s; }
    .btn-white:hover { transform:translateY(-2px); text-decoration:none; color:var(--clr-primary-dark); }
    .btn-outline-white { background:transparent; color:#fff; border:2px solid #fff; font-weight:700; padding:0.75em 2.2em; border-radius:var(--radius-full); font-size:1rem; }
    .btn-outline-white:hover { background:rgba(255,255,255,0.15); text-decoration:none; color:#fff; }

    .features-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1.5rem;
      margin-bottom: 3rem;
    }
    .features-grid--secondary {
      max-width: 620px;
      margin: 0 auto 3rem;
    }
    .feature-card {
      background: var(--clr-surface);
      border: 1px solid var(--clr-border);
      border-radius: var(--radius-md);
      padding: 2rem 1.5rem;
      text-align: center;
    }
    .feature-icon {
      display: block;
      margin: 0 auto 1rem;
      color: var(--clr-primary);
    }
    .feature-card h3 { font-size: 1.1rem; margin-bottom: 0.5rem; color: var(--clr-primary); }
    .feature-card p  { font-size: 0.9rem; color: var(--clr-text-muted); }

    .section-title { font-size: 1.8rem; font-weight: 700; text-align:center; margin-bottom:2rem; }

    @media (min-width: 900px) {
      .features-grid--primary {
        grid-template-columns: repeat(4, minmax(0, 1fr));
      }
      .features-grid--secondary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }
  </style>
</head>
<body>

<!-- Guest nav -->
<header class="navbar" role="banner">
  <div class="nav-container">
    <a href="<?= BASE_URL ?>/index.php" class="nav-logo">
      <img src="<?= BASE_URL ?>/assets/img/logo.svg" alt="EcoTrack" width="36" height="36">
      <span class="logo-text">Eco<strong>Track</strong></span>
    </a>
    <button class="hamburger" id="hamburgerBtn" aria-label="Toggle navigation"
            aria-expanded="false" aria-controls="navMenu">
      <span></span><span></span><span></span>
    </button>
    <nav class="nav-menu" id="navMenu">
      <a href="<?= BASE_URL ?>/login.php">Log In</a>
      <a href="<?= BASE_URL ?>/register.php" class="btn btn-primary btn-sm">Get Started</a>
    </nav>
  </div>
</header>

<main id="mainContent">

  <!-- Hero -->
  <section class="hero">
    <h1>Track. Earn. Make a Difference.</h1>
    <p>EcoTrack rewards your eco-friendly actions with real green points.
       Log activities, join challenges, climb the leaderboard, and redeem
       rewards - all while reducing your carbon footprint.</p>
    <div class="hero-btns">
      <a href="<?= BASE_URL ?>/register.php" class="btn-white">Register</a>
      <a href="<?= BASE_URL ?>/login.php" class="btn-outline-white">Log In</a>
    </div>
  </section>

  <!-- Features -->
  <h2 class="section-title">Why EcoTrack?</h2>
  <div class="features-grid features-grid--primary">
    <div class="feature-card">
      <?= featureIcon($featureIcons['dashboard']) ?>
      <h3>Activity Dashboard</h3>
      <p>Log recycling, energy saving, green transport and more. See your impact grow daily.</p>
    </div>
    <div class="feature-card">
      <?= featureIcon($featureIcons['points']) ?>
      <h3>Points &amp; Badges</h3>
      <p>Earn points for every approved activity. Collect badges and climb the leaderboard.</p>
    </div>
    <div class="feature-card">
      <?= featureIcon($featureIcons['challenge']) ?>
      <h3>Challenges</h3>
      <p>Join Easy, Medium or Hard challenges designed by moderators to push your eco habits.</p>
    </div>
    <div class="feature-card">
      <?= featureIcon($featureIcons['shop']) ?>
      <h3>Green Shop</h3>
      <p>Redeem your points for real eco-friendly rewards - from tote bags to solar chargers.</p>
    </div>
  </div>

  <div class="features-grid features-grid--secondary">
    <div class="feature-card">
      <?= featureIcon($featureIcons['co2']) ?>
      <h3>CO2 Tracker</h3>
      <p>Visualise your cumulative CO2 savings over time with a dynamic live chart.</p>
    </div>
    <div class="feature-card">
      <?= featureIcon($featureIcons['streak']) ?>
      <h3>Daily Streaks</h3>
      <p>Check in every day to keep your streak alive and earn bonus points.</p>
    </div>
  </div>

  <!-- CTA -->
  <div style="text-align:center;padding:3rem 0 1rem;">
    <a href="<?= BASE_URL ?>/register.php" class="btn btn-primary btn-lg">
      Join EcoTrack Today
    </a>
  </div>

</main>

<footer class="site-footer">
  <div class="footer-container">
    <div class="footer-brand">
      <img src="<?= BASE_URL ?>/assets/img/logo.svg" alt="" width="28" height="28">
      <p>EcoTrack - Making sustainability a daily habit.</p>
    </div>
    <div class="footer-links">
      <a href="<?= BASE_URL ?>/login.php">Login</a>
      <a href="<?= BASE_URL ?>/register.php">Register</a>
    </div>
    <p class="footer-copy">&copy; <?= date('Y') ?> EcoTrack. Group 6.</p>
  </div>
</footer>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
