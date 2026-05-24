<?php
/**
 * Teacher Login
 * Thin wrapper that routes to the unified login page with the teacher role pre-selected.
 */

$loginUrl = __DIR__ . '/../auth/login.php';

// Pass role via GET so login.php picks it up
$_GET['role'] = 'teacher';

require $loginUrl;
