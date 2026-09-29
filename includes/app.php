<?php
/**
 * EcoTrack application library: configuration, database access and every
 * domain function, with no side effects. Web pages load includes/bootstrap.php
 * instead, which adds the session, security headers and error handling on
 * top of this. The CLI scripts and the test suite load this file directly.
 */
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
