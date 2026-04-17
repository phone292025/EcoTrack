<?php
/**
 * EcoTrack URL prefix from the web server root to the project root.
 * Keeps links stable across XAMPP/WAMP, subfolders, and PHP's built-in server.
 */
if (defined('BASE_URL')) {
    return;
}

function ecotrack_normalize_path(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/');
}

function ecotrack_normalize_url_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    $path = rtrim($path, '/');
    if ($path === '' || $path === '.') {
        return '';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    return $path === '/' ? '' : $path;
}

function ecotrack_base_from_docroot(string $projectRoot): string
{
    $docRaw = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $docRoot = $docRaw !== '' ? realpath($docRaw) : false;
    if ($docRoot === false) {
        return '';
    }

    $projectRoot = ecotrack_normalize_path($projectRoot);
    $docRoot = ecotrack_normalize_path($docRoot);

    if ($projectRoot === $docRoot) {
        return '';
    }

    if (str_starts_with($projectRoot, $docRoot . '/')) {
        return ecotrack_normalize_url_path(substr($projectRoot, strlen($docRoot)));
    }

    return '';
}

function ecotrack_base_from_script(string $projectRoot): string
{
    $scriptFilenameRaw = $_SERVER['SCRIPT_FILENAME'] ?? '';
    $scriptFilename = $scriptFilenameRaw !== '' ? realpath($scriptFilenameRaw) : false;
    $scriptNameRaw = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '');

    if ($scriptFilename === false || $scriptNameRaw === '') {
        return '';
    }

    $projectRoot = ecotrack_normalize_path($projectRoot);
    $scriptDirFs = ecotrack_normalize_path(dirname($scriptFilename));
    $scriptDirUrl = ecotrack_normalize_url_path(dirname(str_replace('\\', '/', $scriptNameRaw)));

    if ($scriptDirFs === $projectRoot) {
        return $scriptDirUrl;
    }

    if (!str_starts_with($scriptDirFs, $projectRoot . '/')) {
        return '';
    }

    $insideProject = substr($scriptDirFs, strlen($projectRoot));
    $insideProject = ecotrack_normalize_url_path($insideProject);

    if ($insideProject === '') {
        return $scriptDirUrl;
    }

    if ($scriptDirUrl === $insideProject) {
        return '';
    }

    if (str_ends_with($scriptDirUrl, $insideProject)) {
        $base = substr($scriptDirUrl, 0, -strlen($insideProject));
        return ecotrack_normalize_url_path($base);
    }

    return '';
}

$projectRoot = realpath(__DIR__ . '/..');
$baseUrl = '';

if ($projectRoot !== false) {
    $baseUrl = ecotrack_base_from_docroot($projectRoot);

    if ($baseUrl === '') {
        $baseUrl = ecotrack_base_from_script($projectRoot);
    }
}

if (preg_match('#/[^/]+\.php$#i', $baseUrl)) {
    $baseUrl = ecotrack_normalize_url_path(dirname($baseUrl));
}

define('BASE_URL', $baseUrl);
