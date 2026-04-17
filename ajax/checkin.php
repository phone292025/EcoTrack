<?php
/**
 * EcoTrack — Daily Check-in AJAX Endpoint
 * File: ajax/checkin.php
 *
 * Expects: POST with csrf token
 * Returns: JSON {success, new_points, streak, message}
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Only allow XHR POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    jsonResponse(false, ['message' => 'Invalid request.']);
}

// Must be logged in as participant
if (!isLoggedIn() || currentRole() !== 'participant') {
    jsonResponse(false, ['message' => 'Not authorised.']);
}

// Validate CSRF
validateCsrf($_POST['csrf'] ?? '');

$userId  = currentUserId();
$success = dailyCheckIn($userId);

if (!$success) {
    jsonResponse(false, ['message' => 'You have already checked in today. Come back tomorrow!']);
}

// Fetch updated stats
$user = getUserById($userId);
jsonResponse(true, [
    'new_points' => (int)$user['points'],
    'streak'     => (int)$user['streak'],
    'message'    => 'Check-in successful! +5 pts 🌿',
]);
