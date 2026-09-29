/**
 * EcoTrack — Daily check-in button on the participant dashboard.
 * File: assets/js/checkin.js
 *
 * Posts the check-in form to its action URL (ajax/checkin.php) without a
 * page reload, then updates the points shown on the page.
 */

'use strict';

(function initCheckin() {
  const form = document.getElementById('checkinForm');
  const msg = document.getElementById('checkinMsg');
  const btn = document.getElementById('checkinBtn');
  const ptsEl = document.getElementById('dashPoints');
  if (!form || !msg || !btn) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (btn.disabled) return;

    msg.textContent = '';
    msg.className = 'participant-dashboard-checkin__message';
    btn.disabled = true;

    try {
      const res = await fetch(form.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form),
        credentials: 'same-origin',
      });
      const data = await res.json();

      if (data.success) {
        msg.classList.add('is-success');
        msg.textContent = data.message || 'Checked in!';
        btn.textContent = 'Checked in today';
        if (ptsEl && data.new_points != null) ptsEl.textContent = data.new_points;
        const badge = document.getElementById('navPointsBadge');
        if (badge && data.new_points != null) badge.textContent = data.new_points + ' pts';
      } else {
        msg.classList.add('is-error');
        msg.textContent = data.message || 'Check-in failed.';
        // Already checked in is a permanent state for today; anything else
        // is worth letting them retry.
        btn.disabled = res.status === 409;
      }
    } catch (err) {
      msg.classList.add('is-error');
      msg.textContent = 'Network error. Try again.';
      btn.disabled = false;
    }
  });
})();
