<?php
/**
 * EcoTrack — Image uploads.
 *
 * Evidence photos can show people, homes and places, so they are not served
 * straight from the uploads folder. evidence.php checks who is asking first:
 * the participant who submitted it, or a moderator or admin.
 */

const UPLOAD_DIR = __DIR__ . '/../uploads';

/** Stored upload names are always 32 hex characters plus an image extension. */
const UPLOAD_NAME_PATTERN = '/^[a-f0-9]{32}\.(jpe?g|png|gif|webp)$/';

const UPLOAD_TYPES = [
    'image/jpeg' => ['jpg', 'jpeg'],
    'image/png'  => ['png'],
    'image/gif'  => ['gif'],
    'image/webp' => ['webp'],
];

/**
 * Validate and save an uploaded image.
 *
 * @param array  $file   One entry from $_FILES
 * @param string $subdir 'evidence' or 'avatars'
 * @return string|null   Stored filename on success, null on failure
 */
function handleFileUpload(array $file, string $subdir = 'evidence'): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > UPLOAD_MAX_BYTES) {
        return null;
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return null;
    }

    // Sniff the real type with finfo, never $_FILES['type'], which the
    // browser (or an attacker) supplies.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(UPLOAD_TYPES[$mime])) {
        return null;
    }

    // Confirm it really decodes as an image.
    if (@getimagesize($file['tmp_name']) === false) {
        return null;
    }

    // The extension must agree with the sniffed type, so a .png that is
    // actually a JPEG cannot slip through with a mismatched name.
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, UPLOAD_TYPES[$mime], true)) {
        return null;
    }

    $subdir  = in_array($subdir, ['evidence', 'avatars'], true) ? $subdir : 'evidence';
    $newName = bin2hex(random_bytes(16)) . '.' . $ext;
    $destDir = UPLOAD_DIR . '/' . $subdir . '/';

    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        return null;
    }

    if (!move_uploaded_file($file['tmp_name'], $destDir . $newName)) {
        return null;
    }

    return $newName; // Store only the filename in the database, never a path
}

/** Link to an evidence image through the access-checked endpoint. */
function evidenceUrl(string $filename): string
{
    return BASE_URL . '/evidence.php?file=' . rawurlencode($filename);
}

/** Link to a profile picture. */
function avatarUrl(string $filename): string
{
    return BASE_URL . '/uploads/avatars/' . rawurlencode($filename);
}

/** Delete a stored upload, ignoring anything that is not one of ours. */
function deleteUpload(string $subdir, string $filename): void
{
    if (in_array($subdir, ['evidence', 'avatars'], true) && preg_match(UPLOAD_NAME_PATTERN, $filename)) {
        @unlink(UPLOAD_DIR . '/' . $subdir . '/' . $filename);
    }
}
