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

$allowedRoles = array_intersect_key($allowedRoles, array_flip(deploymentAllowedRoles()));

$errors = [];
$email = '';
$urlError = $_GET['error'] ?? '';
$role = trim($_GET['role'] ?? $_POST['role'] ?? '');
$genericLoginError = 'Invalid email or password for the selected portal.';
$tooManyAttemptsError = 'Too many failed sign-in attempts. Please wait a few minutes and try again.';

if (!isset($allowedRoles[$role])) {
    $query = [];

    if ($urlError !== '') {
        $query['error'] = $urlError;
    } elseif ($role !== '' && !isDeploymentRoleAllowed($role)) {
        $query['error'] = 'deployment_role_blocked';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $query['error'] = 'select_role';
    }

    redirect(APP_URL . '/auth/select-role.php' . (!empty($query) ? '?' . http_build_query($query) : ''));
}

$roleMeta = $allowedRoles[$role];

function normalizeLoginEmail(string $email): string
{
    return strtolower(trim($email));
}

function currentLoginUserAgentHash(): string
{
    return hash('sha256', substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512));
}

function recentFailedLoginCounts(PDO $pdo, string $email): array
{
    $windowSeconds = max(60, (int)LOCKOUT_DURATION);
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) FILTER (WHERE COALESCE(new_value->>'email', '') = :email) AS email_count,
            COUNT(*) FILTER (
                WHERE ip_address = :ip
                  AND COALESCE(new_value->>'user_agent_hash', '') = :user_agent_hash
            ) AS client_count
        FROM audit_log
        WHERE action = 'login_failed'
          AND timestamp >= NOW() - ({$windowSeconds} * INTERVAL '1 second')
    ");
    $stmt->execute([
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ':user_agent_hash' => currentLoginUserAgentHash(),
        ':email' => $email,
    ]);

    $counts = $stmt->fetch() ?: [];
    return [
        'email' => (int)($counts['email_count'] ?? 0),
        'client' => (int)($counts['client_count'] ?? 0),
    ];
}

function recordLoginFailure(string $email, string $role, ?int $userId, string $reason): void
{
    try {
        $pdo = getDB(databaseScopeForRole($role));
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (user_id, action, table_affected, record_id, old_value, new_value, ip_address, timestamp)
            VALUES (:uid, 'login_failed', 'users', :rid, NULL, :new, :ip, NOW())
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':rid' => $userId,
            ':new' => json_encode([
                'email' => $email,
                'role' => $role,
                'reason' => $reason,
                'user_agent_hash' => currentLoginUserAgentHash(),
            ]),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);
    } catch (Throwable $e) {
        logException($e, 'Unable to audit failed login.', ['login_role' => $role]);
    }
}

function loginDatabaseForRole(string $role): PDO
{
    return getDB(databaseScopeForRole($role));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $email = trim($_POST['email'] ?? '');
    $normalizedEmail = normalizeLoginEmail($email);
    $password = $_POST['password'] ?? '';

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($password)) {
        $errors[] = 'Please enter your password.';
    }

    if (empty($errors)) {
        $pdo = loginDatabaseForRole($role);
        $failedCounts = recentFailedLoginCounts($pdo, $normalizedEmail);

        if (
            $failedCounts['email'] >= MAX_LOGIN_ATTEMPTS
            || $failedCounts['client'] >= (MAX_LOGIN_ATTEMPTS * 3)
        ) {
            recordLoginFailure($normalizedEmail, $role, null, 'throttled');
            $errors[] = $tooManyAttemptsError;
        } else {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = :email LIMIT 1');
            $stmt->execute([':email' => $normalizedEmail]);
            $user = $stmt->fetch();

            if ($user) {
                $userId = (int)$user['id'];
                if ($user['failed_attempts'] >= MAX_LOGIN_ATTEMPTS && $user['lockout_until'] && strtotime($user['lockout_until']) > time()) {
                    recordLoginFailure($normalizedEmail, $role, $userId, 'account_locked');
                    $errors[] = $tooManyAttemptsError;
                } elseif ($user['password_hash'] === null) {
                    recordLoginFailure($normalizedEmail, $role, $userId, 'missing_password_hash');
                    $errors[] = $genericLoginError;
                } elseif (password_verify($password, $user['password_hash'])) {
                    // user_roles is the source of truth; users.role is only a legacy fallback.
                    $userRoles = getUserRolesForUser($pdo, $userId, (string)$user['role'], true);

                    if (!in_array($role, $userRoles, true)) {
                        recordLoginFailure($normalizedEmail, $role, $userId, 'role_mismatch');
                        $errors[] = $genericLoginError;
                    } elseif (!$user['is_active']) {
                        recordLoginFailure($normalizedEmail, $role, $userId, 'inactive_account');
                        $errors[] = 'Unable to sign in. Contact an administrator if this continues.';
                    } else {
                        $stmt = $pdo->prepare('UPDATE users SET failed_attempts = 0, lockout_until = NULL, last_login = NOW() WHERE id = :id');
                        $stmt->execute([':id' => $userId]);

                        session_regenerate_id(true);
                        $_SESSION['user_id']     = $userId;
                        $_SESSION['user_email']  = $user['email'];
                        $_SESSION['role']        = $role;         // active role = what they selected
                        $_SESSION['all_roles']   = $userRoles;    // all roles they hold
                        $_SESSION['google_avatar'] = $user['google_avatar'];

                        auditLog('login', 'users', $userId);
                        redirect(getRoleDashboardUrl());
                    }
                } else {
                    $attempts = (int)$user['failed_attempts'] + 1;
                    $lockout = $attempts >= MAX_LOGIN_ATTEMPTS ? date('Y-m-d H:i:s', time() + LOCKOUT_DURATION) : null;
                    $stmt = $pdo->prepare('UPDATE users SET failed_attempts = :att, lockout_until = :lock WHERE id = :id');
                    $stmt->execute([':att' => $attempts, ':lock' => $lockout, ':id' => $userId]);

                    recordLoginFailure($normalizedEmail, $role, $userId, 'bad_password');
                    $errors[] = $attempts >= MAX_LOGIN_ATTEMPTS ? $tooManyAttemptsError : $genericLoginError;
                }
            } else {
                recordLoginFailure($normalizedEmail, $role, null, 'unknown_email');
                $errors[] = $genericLoginError;
            }
        }
    }
}

/*
 * The login block above intentionally selects the database from the requested
 * role before querying users. In hybrid mode, teachers/guardians authenticate
 * against Supabase while admin/clerk accounts authenticate against Docker.
 */

$errorMessages = [
    'unauthenticated' => 'Please log in to access that page.',
    'unauthorized' => 'You do not have permission to access that page.',
    'oauth_failed' => 'Sign-in failed. Please try again.',
    'oauth_disabled' => 'Google sign-in has been disabled. Please sign in with email and password.',
    'account_inactive' => 'Your account has been deactivated. Contact an administrator.',
    'role_mismatch' => 'Selected role does not match the account role.',
    'csrf_expired' => 'Your sign-in form expired. Please enter your password and try again.',
    'deployment_role_blocked' => deploymentAccessMessage(),
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
$stylePath = __DIR__ . '/../assets/css/style.css';
$styleVersion = APP_VERSION . '-' . (is_file($stylePath) ? filemtime($stylePath) : time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($roleMeta['label']) ?> Sign In - <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/images/branding/agape-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/style.css?v=<?= e((string)$styleVersion) ?>" rel="stylesheet">
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
                <img src="<?= APP_URL ?>/assets/images/branding/agape-logo.png" alt="Agape Logo" class="auth-left-logo">
                <?= e(APP_NAME) ?>
            </div>

            <h1 class="auth-left-title"><?= e($roleMeta['label']) ?> Portal</h1>
            <p class="auth-left-desc"><?= e($leftDesc) ?></p>

            <div class="auth-left-decoration">
                <span class="auth-left-dot <?= e($role === 'admin' ? 'active' : '') ?>"></span>
                <span class="auth-left-dot <?= e($role === 'clerk' ? 'active' : '') ?>"></span>
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
