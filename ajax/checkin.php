<?php
/**
 * EcoTrack — Daily Check-in AJAX Endpoint
 * File: ajax/checkin.php
 *
 * Expects: POST with csrf token
 * Returns: JSON {success, new_points, streak, message}
 */

require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Only allow XHR POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    jsonResponse(false, ['message' => 'Invalid request.'], 400);
}

// Must be logged in as participant
if (!isLoggedIn() || currentRole() !== 'participant') {
    jsonResponse(false, ['message' => 'Not authorised.'], 403);
}

// Validate CSRF
validateCsrf($_POST['csrf'] ?? '');

$userId = currentUserId();

try {
    $checkedIn = dailyCheckIn($userId);
} catch (Throwable $e) {
    error_log('[EcoTrack checkin] ' . $e->getMessage());
    jsonResponse(false, ['message' => 'Check-in could not be saved. Please try again.'], 500);
}

if (!$checkedIn) {
    // 409 Conflict — already done today, so the button should stay disabled.
    jsonResponse(false, [
        'message' => 'You have already checked in today. Come back tomorrow!',
    ], 409);
}

// Fetch updated stats
$user = getUserById($userId);
refreshSessionPoints((int)($user['points'] ?? 0));

jsonResponse(true, [
    'new_points' => (int)($user['points'] ?? 0),
    'streak'     => (int)($user['streak'] ?? 0),
    'message'    => 'Check-in successful. +5 pts',
]);
