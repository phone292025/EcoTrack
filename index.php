<?php
/**
 * EcoTrack - Landing Page
 * File: index.php
 * Logged-in users go to their dashboard; guests see the landing page.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/paths.php';

if (isLoggedIn()) redirectByRole();

$pageTitle = 'EcoTrack - Sustainable Activity Tracking';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="EcoTrack helps you track eco-friendly activities, earn green points, and make a real environmental impact.">
  <title><?= htmlspecialchars($pageTitle) ?></title>
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
    .feature-icon { font-size: 2.5rem; margin-bottom: 1rem; }
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
      <div class="feature-icon">A</div>
      <h3>Activity Dashboard</h3>
      <p>Log recycling, energy saving, green transport and more. See your impact grow daily.</p>
    </div>
    <div class="feature-card">
      <div class="feature-icon">P</div>
      <h3>Points & Badges</h3>
      <p>Earn points for every approved activity. Collect badges and climb the leaderboard.</p>
    </div>
    <div class="feature-card">
      <div class="feature-icon">C</div>
      <h3>Challenges</h3>
      <p>Join Easy, Medium or Hard challenges designed by moderators to push your eco habits.</p>
    </div>
    <div class="feature-card">
      <div class="feature-icon">S</div>
      <h3>Green Shop</h3>
      <p>Redeem your points for real eco-friendly rewards - from tote bags to solar chargers.</p>
    </div>
  </div>

  <div class="features-grid features-grid--secondary">
    <div class="feature-card">
      <div class="feature-icon">CO</div>
      <h3>CO2 Tracker</h3>
      <p>Visualise your cumulative CO2 savings over time with a dynamic live chart.</p>
    </div>
    <div class="feature-card">
      <div class="feature-icon">D</div>
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
      <img src="<?= BASE_URL ?>/assets/img/logo.svg" alt="" width="28">
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

