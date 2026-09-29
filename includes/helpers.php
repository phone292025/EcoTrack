<?php
/**
 * EcoTrack — Shared helpers: output escaping, JSON, the database clock,
 * input checks and chart colours.
 */

/* =============================================================
 *  OUTPUT
 * ============================================================*/

/**
 * Escape a value for HTML output. Use this on everything user-supplied.
 */
function sanitise(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Terminate with a JSON response (for AJAX endpoints).
 *
 * @param array $data Extra key-value pairs merged into the response
 */
function jsonResponse(bool $success, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success], $data));
    exit;
}

/**
 * Encode data for a <script type="application/json"> block. The HEX flags
 * turn <, >, & and quotes into \u escapes, so no value can close the tag.
 */
function jsonForHtml(mixed $data): string
{
    return json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
}

/**
 * Print the flash messages taken from the session with takeFlash().
 *
 * @param array{success?: string[], error?: string[]} $flash
 */
function renderFlash(array $flash): void
{
    foreach ($flash['error'] ?? [] as $message) {
        echo '<div class="flash-message flash-error" role="alert">', sanitise($message), "</div>\n";
    }
    foreach ($flash['success'] ?? [] as $message) {
        echo '<div class="flash-message flash-success" role="status">', sanitise($message), "</div>\n";
    }
}

/**
 * Evenly spaced colours for however many chart slices exist, so adding a
 * category can never leave a slice uncoloured.
 */
function chartColors(int $count): array
{
    $preset = ['#2d936c', '#f4a261', '#457b9d', '#7b5ea7', '#e07c30', '#3aa17e'];

    if ($count <= count($preset)) {
        return array_slice($preset, 0, max(0, $count));
    }

    $colors = $preset;
    for ($i = count($preset); $i < $count; $i++) {
        $hue = (int)round(($i * 360) / $count);
        $colors[] = sprintf('hsl(%d, 45%%, 45%%)', $hue);
    }

    return $colors;
}

/* =============================================================
 *  CLOCK
 *
 *  PHP and MySQL can sit in different time zones — PHP reads date.timezone
 *  from php.ini while MySQL follows the OS — and near midnight that puts them
 *  on different calendar days. Every date stored by this application comes
 *  from MySQL (CURRENT_TIMESTAMP defaults, CURDATE() comparisons), so the
 *  database clock is the authoritative one. Anything comparing against a
 *  stored date must ask for "today" here rather than using PHP's own clock.
 * ============================================================*/

/**
 * Today's date according to the database, as 'Y-m-d'. Cached per request.
 */
function dbToday(): string
{
    static $today = null;

    if ($today === null) {
        $today = (string)getPDO()->query('SELECT CURDATE()')->fetchColumn();
    }

    return $today;
}

/**
 * Today's date according to the database, as a date object at midnight.
 */
function dbTodayObject(): DateTimeImmutable
{
    return new DateTimeImmutable(dbToday());
}

/* =============================================================
 *  INPUT CHECKS
 *
 *  The browser's maxlength and min/max attributes are a convenience only.
 *  These are the checks that actually hold, and they run before anything
 *  reaches a column that would reject the value.
 * ============================================================*/

/** Longer than $max characters (not bytes)? */
function isTooLong(string $value, int $max): bool
{
    return mb_strlen($value) > $max;
}

/** A LIKE pattern that finds $text anywhere, with % and _ taken literally. */
function likeContains(string $text): string
{
    return '%' . addcslashes($text, '%_\\') . '%';
}

/** A real calendar date written as YYYY-MM-DD. */
function isValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
}

/** Why a username is unacceptable, or null when it is fine. */
function usernameProblem(string $username): ?string
{
    $length = strlen($username);
    if ($length < USERNAME_MIN || $length > USERNAME_MAX) {
        return 'Username must be ' . USERNAME_MIN . '-' . USERNAME_MAX . ' characters.';
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        return 'Username may only contain letters, numbers, and underscores.';
    }
    return null;
}

/** Why an email address is unacceptable, or null when it is fine. */
function emailProblem(string $email): ?string
{
    if (strlen($email) > EMAIL_MAX || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    return null;
}

/** Why a new password is unacceptable, or null when it is fine. */
function passwordProblem(string $password): ?string
{
    if (strlen($password) < PASSWORD_MIN) {
        return 'Password must be at least ' . PASSWORD_MIN . ' characters.';
    }
    // bcrypt ignores everything past 72 bytes, so a longer password would
    // quietly be only partly checked.
    if (strlen($password) > 72) {
        return 'Password must be 72 characters or fewer.';
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'Password must include at least one uppercase letter and one number.';
    }
    return null;
}
