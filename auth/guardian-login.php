<?php
/**
 * Guardian Login
 * Thin wrapper that routes to the unified login page with the guardian role pre-selected.
 */

// Preserve any query string parameters (e.g. ?error=...)
$query = $_GET;
$query['role'] = 'guardian';

$loginUrl = __DIR__ . '/login.php';

// Pass role via GET so login.php picks it up
$_GET['role'] = 'guardian';

require $loginUrl;
