<?php
/**
 * Login Page
 * Role-specific email/password login with brute-force protection.
 */

require_once __DIR__ . '/../includes/session-check.php';
if (isLoggedIn()) {
    redirect(getRoleDashboardUrl());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$allowedRoles = [
    'admin' => [
        'label'       => 'Administrator',
        'icon'        => 'bi-shield-lock-fill',
        'eyebrow'     => 'Administrator Portal',
        'description' => 'Use your administrator credentials to manage records, users, and schedules.',
        'note'        => 'Sign in using your assigned administrator email and password.',
    ],
    'clerk' => [
        'label'       => 'Enrollment Clerk',
        'icon'        => 'bi-clipboard2-check-fill',
        'eyebrow'     => 'Enrollment Clerk Portal',
        'description' => 'Process and manage student enrollment applications.',
        'note'        => 'Clerk accounts are created by the system administrator.',
    ],
    'teacher' => [
        'label'       => 'Teacher',
        'icon'        => 'bi-easel2-fill',
        'eyebrow'     => 'Teacher Portal',
        'description' => 'Access your classes, schedule, and grading tools from one place.',
        'note'        => 'Sign in using your teacher email and password.',
    ],
    'guardian' => [
        'label'       => 'Guardian',
        'icon'        => 'bi-people-fill',
        'eyebrow'     => 'Guardian Portal',
        'description' => 'Check enrollment, grades, payments, and student updates using your guardian account.',
        'note'        => 'Guardian accounts are created by the school administrator.',
    ],
];

$errors = [];
$email = '';
$urlError = $_GET['error'] ?? '';
$role = trim($_GET['role'] ?? $_POST['role'] ?? '');

if (!isset($allowedRoles[$role])) {
    $query = [];

    if ($urlError !== '') {
        $query['error'] = $urlError;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $query['error'] = 'select_role';
    }

    redirect(APP_URL . '/auth/select-role.php' . (!empty($query) ? '?' . http_build_query($query) : ''));
}

$roleMeta = $allowedRoles[$role];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($password)) {
        $errors[] = 'Please enter your password.';
    }

    if (empty($errors)) {
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            if ($user['failed_attempts'] >= MAX_LOGIN_ATTEMPTS && $user['lockout_until'] && strtotime($user['lockout_until']) > time()) {
                $remaining = strtotime($user['lockout_until']) - time();
                $minutes = ceil($remaining / 60);
                $errors[] = "Account is locked. Try again in {$minutes} minute(s).";
            } elseif ($user['password_hash'] === null) {
                $errors[] = 'This account does not have a password yet. Please contact an administrator for a password reset.';
            } elseif (password_verify($password, $user['password_hash'])) {
                // Check selected role is one this account holds (primary OR secondary)
                $rolesStmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :id');
                $rolesStmt->execute([':id' => $user['id']]);
                $userRoles = $rolesStmt->fetchAll(PDO::FETCH_COLUMN);

                // Fallback: if user_roles is empty, use the primary role from users table
                if (empty($userRoles)) {
                    $userRoles = [$user['role']];
                }

                if (!in_array($role, $userRoles, true)) {
                    $errors[] = 'Selected role does not match the account role.';
                } elseif (!$user['is_active']) {
                    $errors[] = 'Your account has been deactivated. Contact an administrator.';
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET failed_attempts = 0, lockout_until = NULL, last_login = NOW() WHERE id = :id');
                    $stmt->execute([':id' => $user['id']]);

                    session_regenerate_id(true);
                    $_SESSION['user_id']     = $user['id'];
                    $_SESSION['user_email']  = $user['email'];
                    $_SESSION['role']        = $role;         // active role = what they selected
                    $_SESSION['all_roles']   = $userRoles;    // all roles they hold
                    $_SESSION['google_avatar'] = $user['google_avatar'];

                    auditLog('login', 'users', $user['id']);
                    redirect(getRoleDashboardUrl());
                }
            } else {
                $attempts = $user['failed_attempts'] + 1;
                $lockout = $attempts >= MAX_LOGIN_ATTEMPTS ? date('Y-m-d H:i:s', time() + LOCKOUT_DURATION) : null;
                $stmt = $pdo->prepare('UPDATE users SET failed_attempts = :att, lockout_until = :lock WHERE id = :id');
                $stmt->execute([':att' => $attempts, ':lock' => $lockout, ':id' => $user['id']]);

                $remaining = MAX_LOGIN_ATTEMPTS - $attempts;
                if ($remaining > 0) {
                    $errors[] = "Invalid password. {$remaining} attempt(s) remaining.";
                } else {
                    $errors[] = 'Account locked for 15 minutes due to too many failed attempts.';
                }
            }
        } else {
            $errors[] = 'No account found with that email address.';
        }
    }
}

$errorMessages = [
    'unauthenticated' => 'Please log in to access that page.',
    'unauthorized' => 'You do not have permission to access that page.',
    'oauth_failed' => 'Sign-in failed. Please try again.',
    'oauth_disabled' => 'Google sign-in has been disabled. Please sign in with email and password.',
    'account_inactive' => 'Your account has been deactivated. Contact an administrator.',
    'role_mismatch' => 'Selected role does not match the account role.',
];

if (isset($errorMessages[$urlError])) {
    $errors[] = $errorMessages[$urlError];
}

$gradientClass = 'gradient-' . $role;
$leftDescriptions = [
    'admin'    => 'Central oversight for students, teachers, attendance, enrollment, and academic records across the institution.',
    'clerk'    => 'Review and process student enrollment applications, manage student records, and coordinate with guardians.',
    'teacher'  => 'Access your class schedule, manage grades, track attendance, and communicate with guardians - all in one place.',
    'guardian' => 'Stay updated on your student\'s enrollment, grades, payments, and academic progress with a single account.',
];
$leftDesc = $leftDescriptions[$role] ?? $roleMeta['description'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($roleMeta['label']) ?> Sign In - <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/jpeg" href="<?= APP_URL ?>/assets/images/branding/agape-logo.jpg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/style.css?v=<?= APP_VERSION ?>" rel="stylesheet">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-split">

        <!-- Left Panel: Gradient Info -->
        <div class="auth-split-left <?= e($gradientClass) ?>">
            <a href="<?= APP_URL ?>/auth/select-role.php" class="auth-left-back">
                <i class="bi bi-arrow-left"></i>
                Choose another portal
            </a>

            <div class="auth-left-badge">
                <img src="<?= APP_URL ?>/assets/images/branding/agape-logo.jpg" alt="Agape Logo" class="auth-left-logo">
                <?= e(APP_NAME) ?>
            </div>

            <h1 class="auth-left-title"><?= e($roleMeta['label']) ?> Portal</h1>
            <p class="auth-left-desc"><?= e($leftDesc) ?></p>

            <div class="auth-left-decoration">
                <span class="auth-left-dot <?= e($role === 'admin' ? 'active' : '') ?>"></span>
                <span class="auth-left-dot <?= e($role === 'teacher' ? 'active' : '') ?>"></span>
                <span class="auth-left-dot <?= e($role === 'guardian' ? 'active' : '') ?>"></span>
            </div>
        </div>

        <!-- Right Panel: Login Form -->
        <div class="auth-split-right">
            <div class="auth-right-watermark" aria-hidden="true"></div>
            <div class="card auth-card">
                <div class="card-body">

                    <div class="auth-card-header">
                        <div class="role-login-icon <?= e('role-' . $role) ?>">
                            <i class="bi <?= e($roleMeta['icon']) ?>"></i>
                        </div>
                        <div class="auth-card-header-text">
                            <h4><?= e($roleMeta['label']) ?> Sign In</h4>
                            <p>Sign in with your <?= e(strtolower($roleMeta['label'])) ?> account</p>
                        </div>
                    </div>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger py-2">
                            <?php foreach ($errors as $err): ?>
                                <div><small><?= e($err) ?></small></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="role-login-note">
                        <small><?= e($roleMeta['note']) ?></small>
                    </div>

                    <form method="POST" action="<?= e(APP_URL . '/auth/login.php?' . http_build_query(['role' => $role])) ?>" id="login-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="role" value="<?= e($role) ?>">

                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email" value="<?= e($email) ?>" placeholder="you@example.com" required autofocus>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100" id="btn-login">
                            <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
                        </button>
                    </form>

                    <p class="text-center mt-3 mb-0 small text-muted">
                        Need access? Contact the system administrator.
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


