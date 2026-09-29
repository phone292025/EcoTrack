<?php
require_once __DIR__ . '/../includes/bootstrap.php';

requireRole('admin');

require __DIR__ . '/../moderator/review_submissions.php';
