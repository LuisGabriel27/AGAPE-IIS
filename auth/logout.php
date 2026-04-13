<?php
/**
 * Logout
 * Destroys the active session and redirects to role selection.
 */

require_once __DIR__ . '/../includes/session-check.php';
require_once __DIR__ . '/../includes/helpers.php';

// Audit before destroying session
if (isset($_SESSION['user_id'])) {
    auditLog('logout', 'users', $_SESSION['user_id']);
}

// Destroy session
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: ' . APP_URL . '/auth/select-role.php');
exit;
