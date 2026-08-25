/**
 * EcoTrack — Main JavaScript
 * File: assets/js/main.js
 *
 * Responsibilities:
 *   1. Hamburger nav toggle (mobile)
 *   2. Client-side form validation (register, login, log_activity)
 *   3. Toast notifications
 *   4. Flash message handling
 *   5. Moderation guard (reject needs a reason)
 */

'use strict';

/* ═══════════════════════════════════════════════════════════
 *  1. HAMBURGER NAVIGATION
 * ═══════════════════════════════════════════════════════════ */
(function initHamburger() {
  const btn  = document.getElementById('hamburgerBtn');
  const menu = document.getElementById('navMenu');
  if (!btn || !menu) return;

  // One close path used by every trigger, so the button icon and the menu
  // can never end up in different states.
  function closeMenu(returnFocus) {
    if (!menu.classList.contains('nav-menu--open')) return;
    menu.classList.remove('nav-menu--open');
    btn.classList.remove('hamburger--open');
    btn.setAttribute('aria-expanded', 'false');
    if (returnFocus) btn.focus();
  }

  function openMenu() {
    menu.classList.add('nav-menu--open');
    btn.classList.add('hamburger--open');
    btn.setAttribute('aria-expanded', 'true');
  }

  btn.addEventListener('click', () => {
    if (menu.classList.contains('nav-menu--open')) {
      closeMenu(false);
    } else {
      openMenu();
    }
  });

  // Close on outside click
  document.addEventListener('click', (e) => {
    if (!btn.contains(e.target) && !menu.contains(e.target)) {
      closeMenu(false);
    }
  });

  // Close on Escape and hand focus back to the toggle
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMenu(true);
  });

  // Following a link closes the menu behind you
  menu.addEventListener('click', (e) => {
    if (e.target.closest('a')) closeMenu(false);
  });
})();

/* ═══════════════════════════════════════════════════════════
 *  2. FORM VALIDATION
 * ═══════════════════════════════════════════════════════════ */

/**
 * Show an error below a field.
 * @param {string} fieldId
 * @param {string} message
 */
function showFieldError(fieldId, message) {
  const field = document.getElementById(fieldId);
  if (!field) return;
  field.classList.add('input--error');
  field.setAttribute('aria-invalid', 'true');
  let errEl = document.getElementById(fieldId + '_err');
  if (!errEl) {
    errEl = document.createElement('span');
    errEl.id        = fieldId + '_err';
    errEl.className = 'field-error';
    errEl.setAttribute('role', 'alert');
    field.insertAdjacentElement('afterend', errEl);
  }
  errEl.textContent = message;
}

/**
 * Clear error for a field.
 */
function clearFieldError(fieldId) {
  const field = document.getElementById(fieldId);
  if (field) {
    field.classList.remove('input--error');
    field.removeAttribute('aria-invalid');
  }
  const errEl = document.getElementById(fieldId + '_err');
  if (errEl) errEl.textContent = '';
}

/** Move focus to the first field that failed, so the user lands on the problem. */
function focusFirstError() {
  const first = document.querySelector('.input--error');
  if (first) first.focus();
}

/**
 * Validate the Registration form.
 * Returns true if valid (form submits), false to block submission.
 */
function validateRegister() {
  let valid = true;
  const fields = ['username', 'email', 'password', 'confirm_password'];
  fields.forEach(f => clearFieldError(f));

  const username = document.getElementById('username')?.value.trim() ?? '';
  const email    = document.getElementById('email')?.value.trim() ?? '';
  const password = document.getElementById('password')?.value ?? '';
  const confirm  = document.getElementById('confirm_password')?.value ?? '';

  if (username.length < 3 || username.length > 50) {
    showFieldError('username', 'Username must be 3-50 characters.');
    valid = false;
  } else if (!/^[a-zA-Z0-9_]+$/.test(username)) {
    showFieldError('username', 'Only letters, numbers and underscores allowed.');
    valid = false;
  }

  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    showFieldError('email', 'Please enter a valid email address.');
    valid = false;
  }

  if (password.length < 8) {
    showFieldError('password', 'Password must be at least 8 characters.');
    valid = false;
  } else if (!/[A-Z]/.test(password) || !/[0-9]/.test(password)) {
    showFieldError('password', 'Must include at least one uppercase letter and one number.');
    valid = false;
  }

  if (password !== confirm) {
    showFieldError('confirm_password', 'Passwords do not match.');
    valid = false;
  }

  if (!valid) focusFirstError();
  return valid;
}

/**
 * Validate the Login form.
 */
function validateLogin() {
  let valid = true;
  clearFieldError('email');
  clearFieldError('password');

  const email    = document.getElementById('email')?.value.trim() ?? '';
  const password = document.getElementById('password')?.value ?? '';

  if (!email) {
    showFieldError('email', 'Enter your email address or username.');
    valid = false;
  }
  if (!password) {
    showFieldError('password', 'Enter your password.');
    valid = false;
  }

  if (!valid) focusFirstError();
  return valid;
}

/**
 * Validate the Log Activity form.
 *
 * The minimum length comes from the field's data-minlength attribute, which
 * PHP renders from the same constant it validates against — so the two checks
 * cannot drift apart.
 */
function validateLogActivity() {
  let valid = true;
  ['cat_id', 'description'].forEach(f => clearFieldError(f));

  const descField = document.getElementById('description');
  const cat  = document.getElementById('cat_id')?.value ?? '';
  const desc = descField?.value.trim() ?? '';
  const min  = parseInt(descField?.dataset.minlength ?? '10', 10);

  if (!cat) {
    showFieldError('cat_id', 'Please select a category.');
    valid = false;
  }
  if (desc.length < min) {
    showFieldError('description', `Description must be at least ${min} characters.`);
    valid = false;
  }

  if (!valid) focusFirstError();
  return valid;
}

/**
 * Preview the selected evidence image before submit.
 */
function initEvidencePreview() {
  const input = document.getElementById('evidence');
  const preview = document.getElementById('evidencePreview');
  const image = document.getElementById('evidencePreviewImage');
  const meta = document.getElementById('evidencePreviewMeta');

  if (!input || !preview || !image || !meta) return;

  let currentObjectUrl = null;

  const resetPreview = () => {
    if (currentObjectUrl) {
      URL.revokeObjectURL(currentObjectUrl);
      currentObjectUrl = null;
    }
    image.removeAttribute('src');
    preview.hidden = true;
    meta.textContent = 'No file selected.';
  };

  input.addEventListener('change', () => {
    const file = input.files?.[0];
    if (!file) {
      resetPreview();
      return;
    }

    if (!file.type.startsWith('image/')) {
      resetPreview();
      input.value = '';
      showToast('Please choose an image file for evidence.', 'error');
      return;
    }

    // Matches the 5 MB server limit, so the user finds out before uploading.
    if (file.size > 5 * 1024 * 1024) {
      resetPreview();
      input.value = '';
      showToast('That image is over the 5 MB limit.', 'error');
      return;
    }

    if (currentObjectUrl) {
      URL.revokeObjectURL(currentObjectUrl);
    }

    currentObjectUrl = URL.createObjectURL(file);
    image.src = currentObjectUrl;
    meta.textContent = `${file.name} - ${(file.size / 1024 / 1024).toFixed(2)} MB`;
    preview.hidden = false;
  });
}

/**
 * A rejection without a reason leaves the participant with nothing to act on,
 * so require the note before the form submits.
 */
function initModerationGuards() {
  document.querySelectorAll('[data-requires-note]').forEach(button => {
    button.addEventListener('click', (e) => {
      const field = document.getElementById(button.dataset.requiresNote);
      if (field && field.value.trim() === '') {
        e.preventDefault();
        field.classList.add('input--error');
        field.focus();
        showToast('Add a short reason so the participant knows what to fix.', 'error');
      }
    });
  });
}

// Attach validators to forms by data-validate attribute
document.addEventListener('DOMContentLoaded', () => {
  const validators = {
    'register':     validateRegister,
    'login':        validateLogin,
    'log_activity': validateLogActivity,
  };

  Object.entries(validators).forEach(([name, fn]) => {
    const form = document.querySelector(`form[data-validate="${name}"]`);
    if (form) {
      form.addEventListener('submit', (e) => {
        if (!fn()) e.preventDefault();
      });
    }
  });

  initEvidencePreview();
  initModerationGuards();
});

/* ═══════════════════════════════════════════════════════════
 *  3. TOAST NOTIFICATION
 * ═══════════════════════════════════════════════════════════ */

/**
 * Display a temporary toast message.
 * @param {string} message
 * @param {'success'|'info'|'error'} type
 */
function showToast(message, type = 'info') {
  let container = document.getElementById('toastContainer');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toastContainer';
    container.setAttribute('aria-live', 'polite');
    container.setAttribute('aria-atomic', 'true');
    document.body.appendChild(container);
  }

  const toast = document.createElement('div');
  toast.className = `toast toast--${type}`;
  toast.textContent = message;
  container.appendChild(toast);
  toast.classList.add('toast--show');

  setTimeout(() => {
    toast.remove();
  }, 4000);
}

/* ═══════════════════════════════════════════════════════════
 *  4. FLASH MESSAGES
 *
 *  Success messages fade on their own. Errors stay until dismissed —
 *  removing an explanation on a timer just makes the user retry blind.
 * ═══════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.flash-message').forEach(el => {
    const isError = el.classList.contains('flash-error');

    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'flash-message__dismiss';
    dismiss.setAttribute('aria-label', 'Dismiss message');
    dismiss.innerHTML = '&times;';
    dismiss.addEventListener('click', () => {
      // Collapse rather than yanking it out, so the page does not jump.
      el.style.height = el.offsetHeight + 'px';
      el.classList.add('flash-message--closing');
      setTimeout(() => el.remove(), 200);
    });
    el.appendChild(dismiss);

    if (!isError) {
      setTimeout(() => dismiss.click(), 5000);
    }
  });
});
