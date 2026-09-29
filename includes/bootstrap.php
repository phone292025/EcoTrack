<?php
/**
 * EcoTrack — The one include every web page starts with.
 *
 *   require_once __DIR__ . '/../includes/bootstrap.php';
 *
 * Loads the application, then sets up error handling, security headers and
 * the session, and checks the signed-in user against the database.
 */
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/http.php';

bootWebRequest();
