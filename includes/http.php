<?php
/**
 * EcoTrack — Per-request setup for web pages: error handling, security
 * headers and the session. Called once by includes/bootstrap.php.
 */

/**
 * Content Security Policy. Scripts and stylesheets load only from this site
 * and no inline <script> runs, so injected markup cannot execute. Inline
 * style attributes are allowed because progress bars set their width with
 * one. frame-ancestors stops other sites from framing a page to trick a
 * click on one of its buttons.
 */
const CONTENT_SECURITY_POLICY = "default-src 'self'; "
    . "script-src 'self'; "
    . "style-src 'self'; "
    . "style-src-attr 'unsafe-inline'; "
    . "img-src 'self' blob:; "
    . "font-src 'self'; "
    . "connect-src 'self'; "
    . "object-src 'none'; "
    . "base-uri 'self'; "
    . "form-action 'self'; "
    . "frame-ancestors 'none'";

function bootWebRequest(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    configureErrorHandling();
    sendSecurityHeaders();
    startSecureSession();
    syncSessionUser();
}

function sendSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    header('Content-Security-Policy: ' . CONTENT_SECURITY_POLICY);
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header_remove('X-Powered-By');
}

/**
 * Keep error details out of pages in normal use, and turn any uncaught
 * exception into a plain error page instead of a stack trace full of SQL.
 * Set APP_DEBUG to true in database/db.local.php to see details while
 * developing.
 */
function configureErrorHandling(): void
{
    ini_set('display_errors', APP_DEBUG ? '1' : '0');
    ini_set('log_errors', '1');

    set_exception_handler(static function (Throwable $e): void {
        // Any open transaction is rolled back by MySQL when the request ends
        // and the connection closes, so nothing half-written is kept.
        error_log('[EcoTrack] Uncaught ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());

        if (!headers_sent()) {
            http_response_code(500);
        }

        if (wantsJson()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
            return;
        }

        $debugMessage = APP_DEBUG ? get_class($e) . ': ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' : '';
        include __DIR__ . '/../layout/error.php';
    });
}
