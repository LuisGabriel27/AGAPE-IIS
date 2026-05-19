<?php
/**
 * Session Initializer & Role Enforcement
 *
 * Usage at the top of every protected page:
 *   require_once __DIR__ . '/../includes/session-check.php';
 *   requireRole('guardian');              // single role
 *   requireRole(['admin','clerk']);       // multiple allowed roles
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/helpers.php';

// Start session with secure cookie params. Local XAMPP can keep HTTP cookies;
// production/proxied HTTPS receives Secure cookies automatically.
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        || (stripos((string)APP_URL, 'https://') === 0);

    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly'  => true,
        'samesite'  => 'Strict',
    ]);
    session_start();
}

/**
 * Check if user is logged in.
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id'], $_SESSION['role']);
}

/**
 * Return all roles the current user holds (primary + secondary).
 * Populated at login time and stored in $_SESSION['all_roles'].
 */
function getAllRoles(): array
{
    return $_SESSION['all_roles'] ?? [$_SESSION['role'] ?? ''];
}

/**
 * Check whether the current user holds a specific role
 * (works for both primary and secondary roles).
 */
function hasRole(string $role): bool
{
    return in_array($role, getAllRoles(), true);
}

/**
 * Require a specific role (or array of roles).
 * Checks against ALL roles the user holds, not just the active one.
 * Redirects guests to role selection.
 *
 * @param string|array $roles
 */
function requireRole($roles): void
{
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/auth/select-role.php?error=unauthenticated');
        exit;
    }

    if (is_string($roles)) {
        $roles = [$roles];
    }

    $userRoles = getAllRoles();
    foreach ($roles as $r) {
        if (in_array($r, $userRoles, true)) {
            return; // access granted
        }
    }

    // Not authorised — redirect to their current active dashboard
    header('Location: ' . getRoleDashboardUrl());
    exit;
}

/**
 * Get the dashboard URL for the currently active role.
 */
function getRoleDashboardUrl(): string
{
    switch ($_SESSION['role'] ?? '') {
        case 'admin':
            return APP_URL . '/admin/admin-dashboard.php';
        case 'clerk':
            return APP_URL . '/admin/clerk-dashboard.php';
        case 'teacher':
            return APP_URL . '/teacher/teacher-dashboard.php';
        case 'guardian':
            return APP_URL . '/guardian/dashboard.php';
        default:
            return APP_URL . '/auth/select-role.php';
    }
}

/**
 * Switch the active role for the current session.
 * Only allows switching to a role the user actually holds.
 */
function switchRole(string $newRole): bool
{
    if (!isLoggedIn()) {
        return false;
    }
    if (!in_array($newRole, getAllRoles(), true)) {
        return false;
    }
    $_SESSION['role'] = $newRole;
    return true;
}
