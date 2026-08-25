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
    // Harden the session cookie before the session starts.
    // HttpOnly keeps JavaScript away from the session id, SameSite blocks
    // the cookie from riding along on cross-site requests, and Secure is
    // enabled automatically when the page is served over HTTPS.
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

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
        include __DIR__ . '/../layout/403.php';   // simple "Access Denied" page
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

/**
 * Raw username from the session. Escape at the point of output with
 * sanitise() — this returns the value, not pre-escaped HTML.
 */
function currentUsername(): string
{
    return (string)($_SESSION['username'] ?? '');
}

/**
 * Points balance cached in the session so shared layout does not have to
 * query the database on every single page render.
 */
function currentPoints(): int
{
    return (int)($_SESSION['points'] ?? 0);
}

/**
 * Keep the cached balance in step after anything that moves points.
 */
function refreshSessionPoints(?int $points = null): void
{
    if ($points !== null) {
        $_SESSION['points'] = $points;
        return;
    }

    if (!isset($_SESSION['user_id'])) {
        return;
    }

    $stmt = getPDO()->prepare('SELECT points FROM users WHERE user_id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $_SESSION['points'] = (int)($stmt->fetchColumn() ?: 0);
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
    $_SESSION['points']   = (int)($user['points'] ?? 0);
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
 * FLASH MESSAGES + POST/REDIRECT/GET
 *
 * Every POST handler should finish with a redirect so that refreshing the
 * page cannot replay the submission. Messages survive the redirect in the
 * session and are consumed exactly once by the page that renders them.
 * -------------------------------------------------------*/

/**
 * Queue a message for the next page render.
 *
 * @param string $type 'success' or 'error'
 */
function setFlash(string $type, string $message): void
{
    if (!in_array($type, ['success', 'error'], true)) {
        $type = 'success';
    }
    $_SESSION['flash'][$type][] = $message;
}

/**
 * Read and clear all queued messages.
 *
 * @return array{success: string[], error: string[]}
 */
function takeFlash(): array
{
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return [
        'success' => $flash['success'] ?? [],
        'error'   => $flash['error']   ?? [],
    ];
}

/**
 * Preserve submitted form values across a failed-validation redirect so the
 * user does not have to retype everything.
 */
function setFormOld(array $values): void
{
    $_SESSION['form_old'] = $values;
}

function takeFormOld(): array
{
    $old = $_SESSION['form_old'] ?? [];
    unset($_SESSION['form_old']);
    return is_array($old) ? $old : [];
}

/**
 * Redirect and stop. Relative targets are resolved against BASE_URL.
 */
function redirectTo(string $target): never
{
    if (!preg_match('#^https?://#i', $target)) {
        if ($target === '' || $target[0] !== '/') {
            $target = '/' . $target;
        }
        $target = BASE_URL . $target;
    }

    header('Location: ' . $target);
    exit;
}

/**
 * Redirect back to the page that was just submitted, dropping the POST.
 */
function redirectToSelf(string $query = ''): never
{
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
    redirectTo($path . ($query !== '' ? '?' . ltrim($query, '?') : ''));
}

/* -------------------------------------------------------
 * LOGIN THROTTLING
 *
 * Repeated failures against the same identifier or the same IP address are
 * recorded and blocked for a cool-off window. This is what stops a script
 * from working through a password list.
 * -------------------------------------------------------*/

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_MINUTES = 15;

function clientIp(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/**
 * Number of failed attempts inside the current window, counted across both
 * the identifier being tried and the IP address doing the trying.
 */
function loginFailureCount(string $identifier): int
{
    $stmt = getPDO()->prepare(
        'SELECT COUNT(*)
         FROM login_attempts
         WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
           AND (identifier = ? OR ip_address = ?)'
    );
    $stmt->execute([LOGIN_LOCKOUT_MINUTES, $identifier, clientIp()]);

    return (int)$stmt->fetchColumn();
}

function isLoginLocked(string $identifier): bool
{
    return loginFailureCount($identifier) >= LOGIN_MAX_ATTEMPTS;
}

function recordLoginFailure(string $identifier): void
{
    $stmt = getPDO()->prepare(
        'INSERT INTO login_attempts (identifier, ip_address) VALUES (?, ?)'
    );
    $stmt->execute([substr($identifier, 0, 100), clientIp()]);
}

/**
 * Clear the slate after a successful login, and opportunistically drop rows
 * that have aged out of every window.
 */
function clearLoginFailures(string $identifier): void
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'DELETE FROM login_attempts WHERE identifier = ? OR ip_address = ?'
    );
    $stmt->execute([substr($identifier, 0, 100), clientIp()]);

    $pdo->prepare(
        'DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)'
    )->execute();
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
