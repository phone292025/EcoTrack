<?php
/**
 * EcoTrack — Shared Page Footer
 * File: layout/footer.php
 */
require_once __DIR__ . '/../includes/paths.php';
$footerRole = $_SESSION['role'] ?? 'guest';
?>
</main><!-- /#mainContent -->

<footer class="site-footer" role="contentinfo">
  <div class="footer-container">
    <div class="footer-brand">
      <img src="<?= BASE_URL ?>/assets/img/logo.svg" alt="" width="28" height="28">
      <p>EcoTrack &mdash; Making sustainability a daily habit.</p>
    </div>
    <div class="footer-links">
      <?php if ($footerRole === 'participant'): ?>
        <a href="<?= BASE_URL ?>/participant/dashboard.php">Dashboard</a>
        <a href="<?= BASE_URL ?>/participant/leaderboard.php">Leaderboard</a>
        <a href="<?= BASE_URL ?>/participant/shop.php">Green Shop</a>
      <?php elseif ($footerRole === 'moderator'): ?>
        <a href="<?= BASE_URL ?>/moderator/dashboard.php">Dashboard</a>
        <a href="<?= BASE_URL ?>/moderator/review_submissions.php">Review queue</a>
      <?php elseif ($footerRole === 'admin'): ?>
        <a href="<?= BASE_URL ?>/admin/dashboard.php">Dashboard</a>
        <a href="<?= BASE_URL ?>/admin/user_management.php">Users</a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/index.php">Home</a>
        <a href="<?= BASE_URL ?>/login.php">Login</a>
      <?php endif; ?>
    </div>
    <p class="footer-copy">&copy; <?= date('Y') ?> EcoTrack. Group 6 &mdash; AAPP012-4-2-RWDD.</p>
  </div>
</footer>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
