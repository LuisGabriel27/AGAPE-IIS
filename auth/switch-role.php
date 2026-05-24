<?php
/**
 * Role Switcher
 * Switches the active role for users who hold multiple roles.
 * Only allows switching to a role the user actually owns (stored in session all_roles).
 */

require_once __DIR__ . '/../includes/session-check.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

if (!isLoggedIn()) {
    redirect(APP_URL . '/auth/select-role.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('warning', 'Role switching requires a secure form submission.');
    redirect(getRoleDashboardUrl());
}

validateCsrf();

$newRole = trim($_POST['role'] ?? '');

if (switchRole($newRole)) {
    auditLog('role_switch', 'users', $_SESSION['user_id'], null, ['to_role' => $newRole]);
    redirect(getRoleDashboardUrl());
} else {
    $message = isDeploymentRoleAllowed($newRole)
        ? 'You do not have access to that role.'
        : deploymentAccessMessage($newRole);
    setFlash('danger', $message);
    redirect(getRoleDashboardUrl());
}
