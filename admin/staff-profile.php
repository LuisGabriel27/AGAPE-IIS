<?php
/**
 * Staff Profile - shared admin/enrollment clerk account profile.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole(['admin', 'clerk']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$userId = (int)($_SESSION['user_id'] ?? 0);

$stmt = $pdo->prepare("SELECT id, email, password_hash, role FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();
if (!$user) {
    setFlash('danger', 'Account could not be loaded.');
    redirect(getRoleDashboardUrl());
}

$profile = getOrCreateUserProfile($pdo, $userId);
if (!$profile) {
    setFlash('danger', 'Staff profile could not be loaded. Please contact the administrator.');
    redirect(getRoleDashboardUrl());
}

$errors = [];
$p = $profile;
$hasPassword = !empty($user['password_hash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $extensionInput = trim($_POST['extension_name'] ?? '');
    $p = [
        'user_id' => $userId,
        'first_name' => trim($_POST['first_name'] ?? ''),
        'middle_name' => trim($_POST['middle_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'extension_name' => normalizeNameExtension($extensionInput),
        'employee_number' => trim($_POST['employee_number'] ?? ''),
        'contact_number' => normalizePhoneNumber11($_POST['contact_number'] ?? ''),
        'office' => trim($_POST['office'] ?? ''),
        'position_title' => trim($_POST['position_title'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'employment_status' => trim($_POST['employment_status'] ?? ''),
        'date_hired' => trim($_POST['date_hired'] ?? ''),
        'emergency_contact_name' => trim($_POST['emergency_contact_name'] ?? ''),
        'emergency_contact_number' => normalizePhoneNumber11($_POST['emergency_contact_number'] ?? ''),
    ];

    $currentPass = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_new_password'] ?? '';

    if ($p['last_name'] === '') $errors[] = 'Last name is required.';
    if (!isValidNameExtension($extensionInput)) $errors[] = nameExtensionErrorMessage();
    if (!isValidPhoneNumber11($p['contact_number'])) $errors[] = phoneNumberErrorMessage('Contact number');
    if (!isValidPhoneNumber11($p['emergency_contact_number'])) $errors[] = phoneNumberErrorMessage('Emergency contact number');
    if ($p['date_hired'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['date_hired'])) {
        $errors[] = 'Date hired must be a valid date.';
    }

    if ($hasPassword && $currentPass === '') {
        $errors[] = 'Current password is required to save changes.';
    } elseif ($hasPassword && !password_verify($currentPass, (string)$user['password_hash'])) {
        $errors[] = 'Current password is incorrect.';
    }

    if ($newPassword !== '' || $confirmPassword !== '') {
        if (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'Confirm new password must match the new password.';
        }
    }

    if (empty($errors) && $p['employee_number'] !== '') {
        $stmt = $pdo->prepare("
            SELECT user_id, first_name, last_name
            FROM user_profiles
            WHERE user_id <> :uid
              AND LOWER(TRIM(employee_number)) = LOWER(TRIM(:employee_number))
            LIMIT 1
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':employee_number' => $p['employee_number'],
        ]);
        $duplicate = $stmt->fetch();
        if ($duplicate) {
            $errors[] = 'This employee number is already used by '
                . format_name((string)($duplicate['first_name'] ?? ''), (string)($duplicate['last_name'] ?? '')) . '.';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("
                UPDATE user_profiles
                SET first_name = :first_name,
                    middle_name = :middle_name,
                    last_name = :last_name,
                    extension_name = :extension_name,
                    employee_number = :employee_number,
                    contact_number = :contact_number,
                    office = :office,
                    position_title = :position_title,
                    address = :address,
                    employment_status = :employment_status,
                    date_hired = :date_hired,
                    emergency_contact_name = :emergency_contact_name,
                    emergency_contact_number = :emergency_contact_number,
                    updated_at = NOW()
                WHERE user_id = :user_id
            ")->execute([
                ':first_name' => $p['first_name'],
                ':middle_name' => $p['middle_name'],
                ':last_name' => $p['last_name'],
                ':extension_name' => nullIfBlank($p['extension_name']),
                ':employee_number' => nullIfBlank($p['employee_number']),
                ':contact_number' => nullIfBlank($p['contact_number']),
                ':office' => nullIfBlank($p['office']),
                ':position_title' => nullIfBlank($p['position_title']),
                ':address' => nullIfBlank($p['address']),
                ':employment_status' => nullIfBlank($p['employment_status']),
                ':date_hired' => nullIfBlank($p['date_hired']),
                ':emergency_contact_name' => nullIfBlank($p['emergency_contact_name']),
                ':emergency_contact_number' => nullIfBlank($p['emergency_contact_number']),
                ':user_id' => $userId,
            ]);

            if ($newPassword !== '') {
                $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id")
                    ->execute([
                        ':hash' => password_hash($newPassword, PASSWORD_BCRYPT),
                        ':id' => $userId,
                    ]);
            }

            auditLog('staff_profile_update', 'user_profiles', $userId, $profile, $p);
            $pdo->commit();
            setFlash('success', 'Profile updated successfully.');
            redirect(APP_URL . '/admin/staff-profile.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            logException($e, 'Staff profile update failed.');
            $errors[] = safeErrorMessage('Profile could not be saved. Please review the form and try again.');
        }
    }
}

$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold"><i class="bi bi-person-circle me-2"></i>My Profile</h4>
    </div>
</div>

<div class="row justify-content-center">
<div class="col-lg-9">
<div class="card">
<div class="card-body p-4">
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger py-2">
            <?php foreach ($errors as $err): ?><div><small><?= e($err) ?></small></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

        <h6 class="fw-semibold text-primary mb-3">Personal Information</h6>
        <div class="row">
            <div class="col-md-3 mb-3">
                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="last_name" value="<?= e((string)($p['last_name'] ?? '')) ?>" required>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">First Name</label>
                <input type="text" class="form-control" name="first_name" value="<?= e((string)($p['first_name'] ?? '')) ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Middle Name</label>
                <input type="text" class="form-control" name="middle_name" value="<?= e((string)($p['middle_name'] ?? '')) ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Extension</label>
                <select class="form-select" name="extension_name">
                    <?php foreach (nameExtensionOptions() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= e(normalizeNameExtension($p['extension_name'] ?? '') === $value ? 'selected' : '') ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Email Address</label>
                <input type="email" class="form-control" value="<?= e((string)$user['email']) ?>" disabled>
                <div class="form-text">Email cannot be changed.</div>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Contact Number</label>
                <input class="form-control" name="contact_number" value="<?= e((string)($p['contact_number'] ?? '')) ?>" <?= phoneInputAttributes() ?>>
            </div>
            <div class="col-12 mb-3">
                <label class="form-label">Address</label>
                <textarea class="form-control" name="address" rows="2"><?= e((string)($p['address'] ?? '')) ?></textarea>
            </div>
        </div>

        <h6 class="fw-semibold text-primary mb-3 mt-2">Employment Details</h6>
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Employee Number</label>
                <input type="text" class="form-control" name="employee_number" value="<?= e((string)($p['employee_number'] ?? '')) ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Position Title</label>
                <input type="text" class="form-control" name="position_title" value="<?= e((string)($p['position_title'] ?? '')) ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Office</label>
                <input type="text" class="form-control" name="office" value="<?= e((string)($p['office'] ?? '')) ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Employment Status</label>
                <select class="form-select" name="employment_status">
                    <option value="">Select...</option>
                    <?php foreach (['Regular', 'Probationary', 'Contractual', 'Part-time', 'Substitute'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= e(($p['employment_status'] ?? '') === $status ? 'selected' : '') ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Date Hired</label>
                <input type="date" class="form-control" name="date_hired" value="<?= e((string)($p['date_hired'] ?? '')) ?>">
            </div>
        </div>

        <h6 class="fw-semibold text-primary mb-3 mt-2">Emergency Contact</h6>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Emergency Contact Name</label>
                <input type="text" class="form-control" name="emergency_contact_name" value="<?= e((string)($p['emergency_contact_name'] ?? '')) ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Emergency Contact Number</label>
                <input class="form-control" name="emergency_contact_number" value="<?= e((string)($p['emergency_contact_number'] ?? '')) ?>" <?= phoneInputAttributes() ?>>
            </div>
        </div>

        <hr>

        <h6 class="fw-semibold text-primary mb-3">Password</h6>
        <?php if ($hasPassword): ?>
            <div class="mb-3">
                <label class="form-label">Current Password <span class="text-danger">*</span></label>
                <input type="password" class="form-control" name="current_password" required autocomplete="current-password">
                <div class="form-text">Required to confirm any changes.</div>
            </div>
        <?php endif; ?>
        <div class="row">
            <div class="col-md-6 mb-4">
                <label class="form-label">New Password <small class="text-muted">(optional)</small></label>
                <input type="password" class="form-control" name="new_password" minlength="8" autocomplete="new-password">
            </div>
            <div class="col-md-6 mb-4">
                <label class="form-label">Confirm New Password</label>
                <input type="password" class="form-control" name="confirm_new_password" minlength="8" autocomplete="new-password">
            </div>
        </div>

        <div class="d-flex justify-content-between">
            <a href="<?= e(getRoleDashboardUrl()) ?>" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Save Changes</button>
        </div>
    </form>
</div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
