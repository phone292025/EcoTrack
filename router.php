<?php
/**
 * EcoTrack — Router for PHP's built-in web server.
 *
 *   php -S localhost:8000 router.php
 *
 * The built-in server ignores .htaccess, so this applies the same rules the
 * .htaccess files give Apache: code, configuration, SQL, scripts and tests
 * are never served, evidence photos only go out through evidence.php, and
 * nothing under uploads/ is ever run as PHP.
 */

// Normalise the path before matching. parse_url() is not used on purpose:
// it reads "//.git/config" as host ".git" plus path "/config", which would
// let a doubled slash walk straight past every rule below.
$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$path = rawurldecode(explode('?', $uri, 2)[0]);
$path = '/' . ltrim((string)preg_replace('#[/\\\\]+#', '/', $path), '/');

$blocked = '#^/(?:database|includes|layout|scripts|tests|\.git|\.github)(?:/|$)'
    . '|^/uploads/evidence/'
    . '|^/uploads/.*\.(?:php\d?|phtml|phar)$'
    . '|\.(?:sql|md|log|bak|ini|sh)$'
    . '|/\.#i';

if (preg_match($blocked, $path)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

// router.php itself is not a page.
if ($path === '/router.php') {
    http_response_code(404);
    return true;
}

// Let the built-in server handle everything else normally.
return false;
