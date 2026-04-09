<?php
/**
 * Role Selection Page
 * Lets users choose which portal they want to access before signing in.
 */

require_once __DIR__ . '/../includes/session-check.php';
if (isLoggedIn()) {
    redirect(getRoleDashboardUrl());
}

require_once __DIR__ . '/../includes/helpers.php';

$errorMessages = [
    'unauthenticated' => 'Please choose your portal and sign in to continue.',
    'select_role' => 'Please select a role before signing in.',
    'oauth_failed' => 'Google sign-in failed. Please try again.',
    'account_inactive' => 'Your account has been deactivated. Contact an administrator.',
];

$roleCards = [
    'admin' => [
        'label'       => 'Administrator',
        'icon'        => 'bi-shield-lock-fill',
        'description' => 'Manage users, schedules, sections, payments, and academic records.',
        'class'       => 'role-admin',
    ],
    'teacher' => [
        'label'       => 'Teacher',
        'icon'        => 'bi-easel2-fill',
        'description' => 'Open your teaching dashboard, class schedule, and grading tools.',
        'class'       => 'role-teacher',
    ],
    'guardian' => [
        'label'       => 'Guardian',
        'icon'        => 'bi-people-fill',
        'description' => 'Track enrollment, grades, payments, and student updates in one place.',
        'class'       => 'role-guardian',
    ],
];

$urlError = $_GET['error'] ?? '';
$pageError = $errorMessages[$urlError] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose Portal - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="role-selection-page">
    <div class="role-selection-shell">
        <?php if ($pageError !== ''): ?>
            <div class="alert alert-danger mb-4"><?= e($pageError) ?></div>
        <?php endif; ?>

        <section class="role-selection-banner">
            <div class="role-selection-badge">
                <i class="bi bi-mortarboard-fill"></i>
                <?= e(APP_NAME) ?>
            </div>
            <h1>Choose your portal</h1>
            <p>Select the role that matches your account, then continue to a dedicated sign-in page.</p>
        </section>

        <section class="role-selection-grid" aria-label="Role selection">
            <?php foreach ($roleCards as $roleKey => $card): ?>
                <a
                    class="role-card <?= e($card['class']) ?>"
                    href="<?= APP_URL ?>/auth/login.php?<?= http_build_query(['role' => $roleKey]) ?>"
                >
                    <div class="role-card-icon">
                        <i class="bi <?= e($card['icon']) ?>"></i>
                    </div>
                    <div class="role-card-label"><?= e($card['label']) ?></div>
                    <p class="role-card-description"><?= e($card['description']) ?></p>
                    <span class="role-card-action">
                        Continue
                        <i class="bi bi-arrow-right"></i>
                    </span>
                </a>
            <?php endforeach; ?>
        </section>

        <div class="role-selection-footer">
            Need a guardian account?
            <a href="<?= APP_URL ?>/auth/signup.php">Sign up here</a>
        </div>
    </div>
</div>
</body>
</html>
