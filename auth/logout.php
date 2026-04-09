<?php
/**
 * Logout
 * Destroys session, revokes Google token if applicable, redirects to role selection.
 */

require_once __DIR__ . '/../includes/session-check.php';
require_once __DIR__ . '/../includes/helpers.php';

// Attempt to revoke Google token if it exists
if (!empty($_SESSION['google_access_token'])) {
    try {
        require_once __DIR__ . '/../config/google.php';
        $client = getGoogleClient();
        $client->setAccessToken($_SESSION['google_access_token']);
        $client->revokeToken();
    } catch (Exception $e) {
        error_log('Google token revoke failed: ' . $e->getMessage());
    }
}

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
