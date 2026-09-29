<?php
/**
 * EcoTrack — Daily check-in endpoint
 * File: ajax/checkin.php
 *
 * Expects: POST with a CSRF token.
 * From the dashboard's fetch() call (X-Requested-With: XMLHttpRequest) it
 * answers with JSON {success, new_points, streak, message}. A plain form
 * post, when JavaScript is off, gets a redirect back to the dashboard with
 * the same message as a flash.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$isAjax = wantsJson();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $isAjax ? jsonResponse(false, ['message' => 'Invalid request.'], 405) : redirectTo('/participant/dashboard.php');
}

requireRole('participant');
validateCsrf($_POST['csrf'] ?? '');

$userId = currentUserId();
$checkedIn = dailyCheckIn($userId);

if (!$checkedIn) {
    $message = 'You have already checked in today. Come back tomorrow!';
    if ($isAjax) {
        // 409 Conflict: already done today, so the button should stay disabled.
        jsonResponse(false, ['message' => $message], 409);
    }
    setFlash('error', $message);
    redirectTo('/participant/dashboard.php');
}

$user = getUserById($userId);
$message = 'Check-in successful. +' . POINTS_DAILY_CHECKIN . ' pts';

if (!$isAjax) {
    setFlash('success', $message);
    redirectTo('/participant/dashboard.php');
}

jsonResponse(true, [
    'new_points' => (int)($user['points'] ?? 0),
    'streak'     => (int)($user['streak'] ?? 0),
    'message'    => $message,
]);
