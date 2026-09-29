<?php
/**
 * EcoTrack — Serve an evidence photo to someone allowed to see it.
 * File: evidence.php?file=<stored name>
 *
 * Allowed: the participant who submitted it, and moderators and admins.
 * The uploads/evidence folder itself is closed to the web, so this is the
 * only way to reach these images.
 */
require_once __DIR__ . '/includes/bootstrap.php';

requireRole('participant', 'moderator', 'admin');

$file = (string)($_GET['file'] ?? '');

if (!preg_match(UPLOAD_NAME_PATTERN, $file)) {
    http_response_code(404);
    exit;
}

if (currentRole() === 'participant') {
    $stmt = getPDO()->prepare('SELECT COUNT(*) FROM activity_logs WHERE evidence = ? AND user_id = ?');
    $stmt->execute([$file, currentUserId()]);
    if ((int)$stmt->fetchColumn() === 0) {
        http_response_code(404);
        exit;
    }
}

$path = UPLOAD_DIR . '/evidence/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!isset(UPLOAD_TYPES[$mime])) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
