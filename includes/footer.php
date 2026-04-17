<?php
/**
 * EcoTrack — Shared Page Footer
 * File: includes/footer.php
 */
require_once __DIR__ . '/paths.php';
?>
</main><!-- /#mainContent -->

<footer class="site-footer" role="contentinfo">
  <div class="footer-container">
    <div class="footer-brand">
      <img src="<?= BASE_URL ?>/assets/img/logo.svg" alt="EcoTrack" width="28" height="28">
      <p>EcoTrack &mdash; Making sustainability a daily habit.</p>
    </div>
    <div class="footer-links">
      <a href="<?= BASE_URL ?>/index.php">Home</a>
      <a href="<?= BASE_URL ?>/participant/leaderboard.php">Leaderboard</a>
      <a href="<?= BASE_URL ?>/participant/shop.php">Green Shop</a>
    </div>
    <p class="footer-copy">&copy; <?= date('Y') ?> EcoTrack. Group 6 &mdash; AAPP012-4-2-RWDD.</p>
  </div>
</footer>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
