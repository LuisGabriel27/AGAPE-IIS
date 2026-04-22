<?php
/**
 * Session Initializer & Role Enforcement
 *
 * Usage at the top of every protected page:
 *   require_once __DIR__ . '/../includes/session-check.php';
 *   requireRole('guardian');          // single role
 *   requireRole(['admin','teacher']); // multiple allowed roles
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/helpers.php';

// Start session with secure cookie params
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false,   // set true in production with HTTPS
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
 * Require a specific role (or array of roles). Redirects guests to role selection.
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

    if (!in_array($_SESSION['role'], $roles, true)) {
        header('Location: ' . getRoleDashboardUrl());
        exit;
    }
}

/**
 * Get the base URL path for the current user's role.
 */
function getRoleDashboardUrl(): string
{
    switch ($_SESSION['role'] ?? '') {
        case 'admin':
            return APP_URL . '/admin/admin-dashboard.php';
        case 'teacher':
            return APP_URL . '/teacher/teacher-dashboard.php';
        case 'guardian':
            return APP_URL . '/guardian/dashboard.php';
        default:
            return APP_URL . '/auth/select-role.php';
    }
}
