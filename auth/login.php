<?php
/**
 * Login Page
 * Role-specific email/password login with brute-force protection + Google OAuth button.
 */

require_once __DIR__ . '/../includes/session-check.php';
if (isLoggedIn()) {
    redirect(getRoleDashboardUrl());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/google.php';

$allowedRoles = [
    'admin' => [
        'label'       => 'Administrator',
        'icon'        => 'bi-shield-lock-fill',
        'eyebrow'     => 'Administrator Portal',
        'description' => 'Use your administrator credentials to manage records, users, and schedules.',
        'note'        => 'Google sign-in works only for administrator accounts that were already linked.',
    ],
    'teacher' => [
        'label'       => 'Teacher',
        'icon'        => 'bi-easel2-fill',
        'eyebrow'     => 'Teacher Portal',
        'description' => 'Access your classes, schedule, and grading tools from one place.',
        'note'        => 'Google sign-in works only for teacher accounts that were already linked.',
    ],
    'guardian' => [
        'label'       => 'Guardian',
        'icon'        => 'bi-people-fill',
        'eyebrow'     => 'Guardian Portal',
        'description' => 'Check enrollment, grades, payments, and student updates using your guardian account.',
        'note'        => 'New guardian accounts can continue with Google or sign up with email below.',
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
                $errors[] = 'This account uses Google Sign-In. Please use the Google button below.';
            } elseif (password_verify($password, $user['password_hash'])) {
                if ($role !== $user['role']) {
                    $errors[] = 'Selected role does not match the account role.';
                } elseif (!$user['is_active']) {
                    $errors[] = 'Your account has been deactivated. Contact an administrator.';
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET failed_attempts = 0, lockout_until = NULL, last_login = NOW() WHERE id = :id');
                    $stmt->execute([':id' => $user['id']]);

                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
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

$client = getGoogleClient();
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;
$_SESSION['oauth_intended_role'] = $role;
$client->setState($state);
$googleAuthUrl = $client->createAuthUrl();

$errorMessages = [
    'unauthenticated' => 'Please log in to access that page.',
    'unauthorized' => 'You do not have permission to access that page.',
    'oauth_failed' => 'Google sign-in failed. Please try again.',
    'account_inactive' => 'Your account has been deactivated. Contact an administrator.',
    'role_mismatch' => 'Selected role does not match the account role.',
    'google_role_unavailable' => 'Only guardian accounts can be created through Google sign-in.',
];

if (isset($errorMessages[$urlError])) {
    $errors[] = $errorMessages[$urlError];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($roleMeta['label']) ?> Sign In - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="auth-wrapper">
    <div class="card auth-card">
        <div class="card-body">
            <a href="<?= APP_URL ?>/auth/select-role.php" class="auth-back-link">
                <i class="bi bi-arrow-left"></i>
                Choose another portal
            </a>

            <div class="text-center mb-4">
                <div class="role-login-icon <?= e('role-' . $role) ?>">
                    <i class="bi <?= e($roleMeta['icon']) ?>"></i>
                </div>
                <div class="role-login-eyebrow"><?= e($roleMeta['eyebrow']) ?></div>
                <h4 class="mt-2 fw-bold"><?= e($roleMeta['label']) ?> Sign In</h4>
                <p class="text-muted small mb-0"><?= e($roleMeta['description']) ?></p>
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

            <a href="<?= e($googleAuthUrl) ?>" class="btn btn-google w-100 mb-2" id="btn-google-login">
                <svg width="20" height="20" viewBox="0 0 48 48"><path fill="#4285F4" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#34A853" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59A14.5 14.5 0 019.5 24c0-1.59.28-3.14.76-4.59l-7.98-6.19A23.998 23.998 0 000 24c0 3.77.9 7.35 2.56 10.53l7.97-5.94z"/><path fill="#EA4335" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 5.94C6.51 42.62 14.62 48 24 48z"/></svg>
                Sign in with Google
            </a>

            <div class="divider-text"><span>or sign in with email</span></div>

            <form method="POST" action="<?= APP_URL ?>/auth/login.php?<?= http_build_query(['role' => $role]) ?>" id="login-form">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="role" value="<?= e($role) ?>">

                <div class="mb-3">
                    <label for="email" class="form-label">Email address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" class="form-control" id="email" name="email" value="<?= e($email) ?>" required autofocus>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100" id="btn-login">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
                </button>
            </form>

            <?php if ($role === 'guardian'): ?>
                <p class="text-center mt-3 mb-0 small">
                    Don't have an account? <a href="<?= APP_URL ?>/auth/signup.php">Sign up as Guardian</a>
                </p>
            <?php else: ?>
                <p class="text-center mt-3 mb-0 small text-muted">
                    Need access to this portal? Please contact the system administrator.
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
