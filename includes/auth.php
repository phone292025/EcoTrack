<?php
require_once __DIR__ . '/paths.php';

/**
 * EcoTrack — Authentication & Session Helpers
 * File: includes/auth.php
 *
 * Include this at the TOP of every protected page.
 * Example:
 *   require_once __DIR__ . '/../includes/auth.php';
 *   requireRole('participant');          // only participants
 *   requireRole('moderator', 'admin');   // moderators OR admins
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* -------------------------------------------------------
 * requireRole()
 * Redirect to login if not authenticated.
 * Redirect to 403 if authenticated but wrong role.
 * -------------------------------------------------------*/
function requireRole(string ...$roles): void
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    if (!in_array($_SESSION['role'], $roles, true)) {
        http_response_code(403);
        include __DIR__ . '/403.php';   // simple "Access Denied" page
        exit;
    }
}

/* -------------------------------------------------------
 * isLoggedIn()
 * -------------------------------------------------------*/
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

/* -------------------------------------------------------
 * currentUserId() / currentRole()
 * -------------------------------------------------------*/
function currentUserId(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function currentRole(): string
{
    return $_SESSION['role'] ?? '';
}

function currentUsername(): string
{
    return htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
}

/* -------------------------------------------------------
 * loginUser()
 * Called after password_verify() succeeds in login.php
 * -------------------------------------------------------*/
function loginUser(array $user): void
{
    // Prevent session fixation
    session_regenerate_id(true);

    $_SESSION['user_id']  = (int)$user['user_id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['role'];
}

/* -------------------------------------------------------
 * logoutUser()
 * -------------------------------------------------------*/
function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/* -------------------------------------------------------
 * CSRF Token Generation & Validation
 * Usage (in form):  <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
 * Usage (handler):  validateCsrf($_POST['csrf'] ?? '');
 * -------------------------------------------------------*/
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrf(string $token): void
{
    if (!hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        exit('Invalid request token. Please go back and try again.');
    }
}

/* -------------------------------------------------------
 * redirectByRole()
 * Send user to their correct dashboard after login.
 * -------------------------------------------------------*/
function redirectByRole(): void
{
    $map = [
        'admin'       => BASE_URL . '/admin/dashboard.php',
        'moderator'   => BASE_URL . '/moderator/dashboard.php',
        'participant' => BASE_URL . '/participant/dashboard.php',
    ];
    $dest = $map[$_SESSION['role']] ?? BASE_URL . '/login.php';
    header('Location: ' . $dest);
    exit;
}

