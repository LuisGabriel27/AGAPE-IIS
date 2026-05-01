<?php
/**
 * Role Switcher
 * Switches the active role for users who hold multiple roles.
 * Only allows switching to a role the user actually owns (stored in session all_roles).
 */

require_once __DIR__ . '/../includes/session-check.php';
require_once __DIR__ . '/../includes/helpers.php';

if (!isLoggedIn()) {
    redirect(APP_URL . '/auth/select-role.php');
}

$newRole = trim($_GET['role'] ?? '');

if (switchRole($newRole)) {
    auditLog('role_switch', 'users', $_SESSION['user_id'], null, ['to_role' => $newRole]);
    redirect(getRoleDashboardUrl());
} else {
    setFlash('danger', 'You do not have access to that role.');
    redirect(getRoleDashboardUrl());
}
