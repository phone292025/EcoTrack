/**
 * EcoTrack — Main JavaScript
 * File: assets/js/main.js
 *
 * Responsibilities:
 *   1. Hamburger nav toggle (mobile)
 *   2. Client-side form validation (register, login, log_activity)
 *   3. Toast notifications
 *   4. Flash message auto-dismiss
 *   5. Active nav link highlight
 */

'use strict';

/* ═══════════════════════════════════════════════════════════
 *  1. HAMBURGER NAVIGATION
 * ═══════════════════════════════════════════════════════════ */
(function initHamburger() {
  const btn  = document.getElementById('hamburgerBtn');
  const menu = document.getElementById('navMenu');
  if (!btn || !menu) return;

  btn.addEventListener('click', () => {
    const isOpen = menu.classList.toggle('nav-menu--open');
    btn.setAttribute('aria-expanded', isOpen);
    btn.classList.toggle('hamburger--open', isOpen);
  });

  // Close on outside click
  document.addEventListener('click', (e) => {
    if (!btn.contains(e.target) && !menu.contains(e.target)) {
      menu.classList.remove('nav-menu--open');
      btn.setAttribute('aria-expanded', 'false');
      btn.classList.remove('hamburger--open');
    }
  });

  // Close on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      menu.classList.remove('nav-menu--open');
      btn.setAttribute('aria-expanded', 'false');
    }
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
  if (field) field.classList.remove('input--error');
  const errEl = document.getElementById(fieldId + '_err');
  if (errEl) errEl.textContent = '';
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
    showFieldError('username', 'Username must be 3–50 characters.');
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
    showFieldError('email', 'Email is required.');
    valid = false;
  }
  if (!password) {
    showFieldError('password', 'Password is required.');
    valid = false;
  }

  return valid;
}

/**
 * Validate the Log Activity form.
 */
function validateLogActivity() {
  let valid = true;
  ['cat_id', 'description'].forEach(f => clearFieldError(f));

  const cat  = document.getElementById('cat_id')?.value ?? '';
  const desc = document.getElementById('description')?.value.trim() ?? '';

  if (!cat) {
    showFieldError('cat_id', 'Please select a category.');
    valid = false;
  }
  if (desc.length < 10) {
    showFieldError('description', 'Description must be at least 10 characters.');
    valid = false;
  }

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
      showToast('Please choose an image file for evidence.', 'error');
      return;
    }

    if (currentObjectUrl) {
      URL.revokeObjectURL(currentObjectUrl);
    }

    currentObjectUrl = URL.createObjectURL(file);
    image.src = currentObjectUrl;
    meta.textContent = `${file.name} • ${(file.size / 1024 / 1024).toFixed(2)} MB`;
    preview.hidden = false;
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

  // Auto dismiss after 4 s
  setTimeout(() => {
    toast.remove();
  }, 4000);
}

/* ═══════════════════════════════════════════════════════════
 *  5. FLASH MESSAGE AUTO-DISMISS
 * ═══════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.flash-message').forEach(el => {
    setTimeout(() => {
      el.remove();
    }, 5000);
  });

  // Highlight active nav link
  const currentPath = window.location.pathname;
  document.querySelectorAll('.nav-menu a').forEach(link => {
    if (link.getAttribute('href') && currentPath.endsWith(link.getAttribute('href').split('/').pop())) {
      link.classList.add('nav-link--active');
      link.setAttribute('aria-current', 'page');
    }
  });
});
