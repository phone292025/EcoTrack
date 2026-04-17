<?php
/**
 * EcoTrack — Logout
 * File: logout.php
 */
require_once __DIR__ . '/includes/auth.php';
logoutUser();
header('Location: ' . BASE_URL . '/login.php');
exit;
