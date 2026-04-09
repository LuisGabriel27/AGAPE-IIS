<?php
/**
 * Signup Page — Guardian Self-Registration
 * Email/password registration + Google OAuth signup button.
 */

require_once __DIR__ . '/../includes/session-check.php';
if (isLoggedIn()) {
    redirect(getRoleDashboardUrl());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/google.php';

$errors    = [];
$formData  = [
    'full_name'     => '',
    'email'         => '',
    'contact'       => '',
    'address'       => '',
    'relationship'  => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $formData['full_name']    = trim($_POST['full_name'] ?? '');
    $formData['email']        = trim($_POST['email'] ?? '');
    $formData['contact']      = trim($_POST['contact'] ?? '');
    $formData['address']      = trim($_POST['address'] ?? '');
    $formData['relationship'] = trim($_POST['relationship'] ?? '');
    $password                 = $_POST['password'] ?? '';
    $passwordConfirm          = $_POST['password_confirm'] ?? '';

    // Validation
    if (empty($formData['full_name'])) $errors[] = 'Full name is required.';
    if (empty($formData['email']) || !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $passwordConfirm) $errors[] = 'Passwords do not match.';
    if (empty($formData['contact'])) $errors[] = 'Contact number is required.';
    if (empty($formData['address'])) $errors[] = 'Address is required.';
    if (empty($formData['relationship'])) $errors[] = 'Relationship to student is required.';

    // Check email uniqueness
    if (empty($errors)) {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $formData['email']]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with this email already exists.';
        }
    }

    // Create account
    if (empty($errors)) {
        $pdo = getDB();
        try {
            $pdo->beginTransaction();

            // Insert user
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, role, is_active, created_at) VALUES (:email, :hash, 'guardian', 1, NOW())");
            $stmt->execute([':email' => $formData['email'], ':hash' => $hash]);
            $userId = $pdo->lastInsertId();

            // Insert guardian profile
            $stmt = $pdo->prepare("INSERT INTO guardians (user_id, full_name, contact_number, address, relationship_to_student) VALUES (:uid, :name, :contact, :addr, :rel)");
            $stmt->execute([
                ':uid'     => $userId,
                ':name'    => $formData['full_name'],
                ':contact' => $formData['contact'],
                ':addr'    => $formData['address'],
                ':rel'     => $formData['relationship'],
            ]);

            $pdo->commit();

            // Auto-login
            session_regenerate_id(true);
            $_SESSION['user_id']       = $userId;
            $_SESSION['user_email']    = $formData['email'];
            $_SESSION['role']          = 'guardian';
            $_SESSION['google_avatar'] = null;

            auditLog('signup', 'users', (int)$userId);
            redirect(APP_URL . '/guardian/dashboard.php');
        } catch (Exception $ex) {
            $pdo->rollBack();
            error_log('Signup error: ' . $ex->getMessage());
            $errors[] = 'An error occurred during registration. Please try again.';
        }
    }
}

// Google OAuth URL
$client = getGoogleClient();
$state  = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;
$_SESSION['oauth_intended_role'] = 'guardian';
$client->setState($state);
$googleAuthUrl = $client->createAuthUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up — <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="auth-wrapper">
    <div class="card auth-card" style="max-width:520px;">
        <div class="card-body">
            <div class="text-center mb-4">
                <i class="bi bi-mortarboard-fill text-primary" style="font-size:2.5rem;"></i>
                <h4 class="mt-2 fw-bold">Create Guardian Account</h4>
                <p class="text-muted small">Register to manage your student's enrollment</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger py-2">
                    <?php foreach ($errors as $err): ?>
                        <div><small><?= e($err) ?></small></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Google Sign-Up -->
            <a href="<?= e($googleAuthUrl) ?>" class="btn btn-google w-100 mb-2" id="btn-google-signup">
                <svg width="20" height="20" viewBox="0 0 48 48"><path fill="#4285F4" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#34A853" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59A14.5 14.5 0 019.5 24c0-1.59.28-3.14.76-4.59l-7.98-6.19A23.998 23.998 0 000 24c0 3.77.9 7.35 2.56 10.53l7.97-5.94z"/><path fill="#EA4335" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 5.94C6.51 42.62 14.62 48 24 48z"/></svg>
                Sign up with Google
            </a>

            <div class="divider-text"><span>or register with email</span></div>

            <form method="POST" action="" id="signup-form">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                <div class="mb-3">
                    <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="full_name" name="full_name" value="<?= e($formData['full_name']) ?>" required>
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= e($formData['email']) ?>" required>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password" name="password" required minlength="8">
                        <div class="form-text">Minimum 8 characters</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="password_confirm" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password_confirm" name="password_confirm" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="contact" class="form-label">Contact Number <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="contact" name="contact" value="<?= e($formData['contact']) ?>" required>
                </div>

                <div class="mb-3">
                    <label for="address" class="form-label">Address <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="address" name="address" rows="2" required><?= e($formData['address']) ?></textarea>
                </div>

                <div class="mb-3">
                    <label for="relationship" class="form-label">Relationship to Student <span class="text-danger">*</span></label>
                    <select class="form-select" id="relationship" name="relationship" required>
                        <option value="">Select...</option>
                        <option value="Parent" <?= $formData['relationship'] === 'Parent' ? 'selected' : '' ?>>Parent</option>
                        <option value="Guardian" <?= $formData['relationship'] === 'Guardian' ? 'selected' : '' ?>>Guardian</option>
                        <option value="Sibling" <?= $formData['relationship'] === 'Sibling' ? 'selected' : '' ?>>Sibling</option>
                        <option value="Other" <?= $formData['relationship'] === 'Other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary w-100" id="btn-signup">
                    <i class="bi bi-person-plus me-1"></i>Create Account
                </button>
            </form>

            <p class="text-center mt-3 mb-0 small">
                Already have an account? <a href="<?= APP_URL ?>/auth/login.php?role=guardian">Sign in</a>
            </p>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
