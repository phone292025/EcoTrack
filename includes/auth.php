<?php
/**
 * EcoTrack — Sessions, authentication, CSRF, flash messages and redirects.
 * File: includes/auth.php
 *
 * Pages do not include this directly. They load includes/bootstrap.php,
 * which starts the session and then protects the page:
 *
 *   require_once __DIR__ . '/../includes/bootstrap.php';
 *   requireRole('participant');          // only participants
 *   requireRole('moderator', 'admin');   // moderators OR admins
 */

require_once __DIR__ . '/paths.php';

const LOGIN_MAX_ATTEMPTS_PER_ACCOUNT = 5;
const LOGIN_MAX_ATTEMPTS_PER_IP      = 20;
const LOGIN_LOCKOUT_MINUTES          = 15;
const SESSION_IDLE_MINUTES           = 120;

/* =============================================================
 *  SESSION
 * ============================================================*/

/**
 * Start the session with a hardened cookie. HttpOnly keeps JavaScript away
 * from the session id, SameSite blocks the cookie from riding along on
 * cross-site requests, Secure is on whenever the page is served over HTTPS,
 * and strict mode refuses session ids the server never issued.
 */
function startSecureSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('ECOTRACKSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_URL !== '' ? BASE_URL . '/' : '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * A short fingerprint of the user's current password hash, kept in the
 * session. Changing the password changes the hash, so every other session
 * signed in with the old password stops matching and is logged out.
 */
function sessionFingerprint(string $passwordHash): string
{
    return hash('sha256', 'ecotrack-session|' . $passwordHash);
}

/**
 * Check the signed-in user against the database on every request.
 *
 * The session only remembers who logged in. Whether they may still act, and
 * in which role, is the database's call: an account that was deleted, had
 * its password changed, or sat idle too long is logged out here, and a role
 * change by an admin takes effect on the user's very next click.
 */
function syncSessionUser(): void
{
    if (!isset($_SESSION['user_id'])) {
        return;
    }

    $lastSeen = (int)($_SESSION['last_seen'] ?? 0);
    if ($lastSeen > 0 && time() - $lastSeen > SESSION_IDLE_MINUTES * 60) {
        endSession('You were logged out after ' . (SESSION_IDLE_MINUTES / 60) . ' hours of inactivity. Please log in again.');
        return;
    }

    $stmt = getPDO()->prepare('SELECT username, role, points, password FROM users WHERE user_id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !hash_equals((string)($_SESSION['auth_fp'] ?? ''), sessionFingerprint((string)$user['password']))) {
        endSession('Your session has ended. Please log in again.');
        return;
    }

    $_SESSION['username']  = $user['username'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['points']    = (int)$user['points'];
    $_SESSION['last_seen'] = time();
}

/**
 * Sign the user out but keep a fresh, empty session so a message can be
 * shown on the next page.
 */
function endSession(string $message = ''): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    if ($message !== '') {
        setFlash('error', $message);
    }
}

/**
 * Called after password_verify() succeeds in login.php.
 */
function loginUser(array $user): void
{
    // A new session id prevents session fixation, and a new CSRF token means
    // a token seen before login is worthless after it.
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);

    $_SESSION['user_id']   = (int)$user['user_id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['points']    = (int)($user['points'] ?? 0);
    $_SESSION['auth_fp']   = sessionFingerprint((string)$user['password']);
    $_SESSION['last_seen'] = time();
}

/**
 * After the signed-in user changes their own password: keep this session
 * valid while every other session for the account is logged out.
 */
function rememberPasswordChange(string $newHash): void
{
    session_regenerate_id(true);
    $_SESSION['auth_fp'] = sessionFingerprint($newHash);
}

function logoutUser(): void
{
    endSession();
}

/* =============================================================
 *  WHO IS SIGNED IN
 * ============================================================*/

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function currentUserId(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function currentRole(): string
{
    return (string)($_SESSION['role'] ?? '');
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
 * Points balance cached in the session. syncSessionUser() refreshes it on
 * every request, and awardPoints() updates it as soon as it changes.
 */
function currentPoints(): int
{
    return (int)($_SESSION['points'] ?? 0);
}

/**
 * Update the cached balance, from the database when no value is given.
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

/**
 * Stop unless the signed-in user has one of the given roles.
 * Guests go to the login page; the wrong role gets a 403.
 */
function requireRole(string ...$roles): void
{
    if (!isLoggedIn()) {
        if (wantsJson()) {
            jsonResponse(false, ['message' => 'Please log in again.'], 401);
        }
        redirectTo('/login.php');
    }

    if (!in_array(currentRole(), $roles, true)) {
        if (wantsJson()) {
            jsonResponse(false, ['message' => 'Not authorised.'], 403);
        }
        http_response_code(403);
        include __DIR__ . '/../layout/403.php';
        exit;
    }
}

/**
 * Send the user to their dashboard after login.
 */
function redirectByRole(): never
{
    $map = [
        'admin'       => '/admin/dashboard.php',
        'moderator'   => '/moderator/dashboard.php',
        'participant' => '/participant/dashboard.php',
    ];

    redirectTo($map[currentRole()] ?? '/login.php');
}

/** Did the browser ask for JSON (the check-in button's fetch() call)? */
function wantsJson(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

/* =============================================================
 *  CSRF
 *
 *  Form:    <input type="hidden" name="csrf" value="<?= sanitise(csrfToken()) ?>">
 *  Handler: validateCsrf($_POST['csrf'] ?? '');
 * ============================================================*/

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrf(mixed $token): void
{
    if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
        if (wantsJson()) {
            jsonResponse(false, ['message' => 'Your session expired. Refresh the page and try again.'], 403);
        }
        http_response_code(403);
        exit('Invalid request token. Please go back, refresh the page and try again.');
    }
}

/* =============================================================
 *  FLASH MESSAGES + POST/REDIRECT/GET
 *
 *  Every POST handler finishes with a redirect so that refreshing the page
 *  cannot replay the submission. Messages survive the redirect in the
 *  session and are consumed exactly once by the page that renders them.
 * ============================================================*/

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
 * Queue the outcome of a domain action: {ok: bool, message: string}.
 */
function flashResult(array $result): void
{
    setFlash(!empty($result['ok']) ? 'success' : 'error', (string)($result['message'] ?? ''));
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
 * Redirect within the application and stop. Targets are paths relative to
 * the project root, e.g. '/login.php'.
 */
function redirectTo(string $target): never
{
    if ($target === '' || $target[0] !== '/') {
        $target = '/' . $target;
    }

    header('Location: ' . BASE_URL . $target);
    exit;
}

/**
 * Redirect back to the page that was just submitted, dropping the POST.
 */
function redirectToSelf(string $query = ''): never
{
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';

    // REQUEST_URI already includes the base path, so strip it before
    // redirectTo() adds it back.
    if (BASE_URL !== '' && str_starts_with($path, BASE_URL . '/')) {
        $path = substr($path, strlen(BASE_URL));
    }

    redirectTo($path . ($query !== '' ? '?' . ltrim($query, '?') : ''));
}

/* =============================================================
 *  LOGIN THROTTLING
 *
 *  Failed logins are recorded per account and per IP address. Five misses on
 *  one account lock that account for fifteen minutes. The per-IP limit is
 *  higher, because a campus network puts many honest people behind one
 *  address, but it still stops one machine from spraying guesses across
 *  many accounts.
 * ============================================================*/

function clientIp(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/**
 * The throttle key for a login attempt. An existing account is keyed by its
 * id, so typing the username or the email counts against the same limit.
 */
function loginThrottleKey(string $identifier, ?array $user): string
{
    return $user ? 'user:' . (int)$user['user_id'] : 'name:' . mb_strtolower(trim($identifier));
}

/**
 * Failed attempts on this account inside the current window.
 */
function loginFailureCount(string $key): int
{
    $stmt = getPDO()->prepare(
        'SELECT COUNT(*)
         FROM login_attempts
         WHERE identifier = ? AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)'
    );
    $stmt->execute([substr($key, 0, 100), LOGIN_LOCKOUT_MINUTES]);

    return (int)$stmt->fetchColumn();
}

function isLoginLocked(string $key): bool
{
    if (loginFailureCount($key) >= LOGIN_MAX_ATTEMPTS_PER_ACCOUNT) {
        return true;
    }

    $stmt = getPDO()->prepare(
        'SELECT COUNT(*)
         FROM login_attempts
         WHERE ip_address = ? AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)'
    );
    $stmt->execute([clientIp(), LOGIN_LOCKOUT_MINUTES]);

    return (int)$stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS_PER_IP;
}

function recordLoginFailure(string $key): void
{
    getPDO()->prepare('INSERT INTO login_attempts (identifier, ip_address) VALUES (?, ?)')
        ->execute([substr($key, 0, 100), clientIp()]);
}

/**
 * Clear this account's failures after a successful login, and drop rows that
 * have aged out of every window.
 *
 * Only this account's rows. Clearing everything recorded for the IP address
 * would let someone reset the counter against another account by logging
 * into their own in between guesses.
 */
function clearLoginFailures(string $key): void
{
    $pdo = getPDO();
    $pdo->prepare('DELETE FROM login_attempts WHERE identifier = ?')->execute([substr($key, 0, 100)]);
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
}
