<?php
/**
 * Guardian Profile Edit — Expanded fields including emergency contact.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo    = getDB();
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$user = $stmt->fetch();

$guardian = getOrCreateGuardianProfile($pdo, (int)$userId);
if (!$guardian) {
    setFlash('danger', 'Guardian profile could not be loaded. Please contact the administrator.');
    redirect(APP_URL . '/guardian/dashboard.php');
}

$hasPassword = !empty($user['password_hash']);
$errors      = [];
$g = $guardian;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $g = [
        'id' => $guardian['id'],
        'user_id' => $guardian['user_id'],
        'first_name' => trim($_POST['first_name'] ?? ''),
        'middle_name' => trim($_POST['middle_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'extension_name' => normalizeNameExtension($_POST['extension_name'] ?? ''),
        'contact_number' => normalizePhoneNumber11($_POST['contact'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'relationship_to_student' => trim($_POST['relationship'] ?? ''),
        'occupation' => trim($_POST['occupation'] ?? ''),
        'civil_status' => trim($_POST['civil_status'] ?? ''),
        'nationality' => trim($_POST['nationality'] ?? 'Filipino') ?: 'Filipino',
        'religion' => trim($_POST['religion'] ?? ''),
        'emergency_contact_name' => trim($_POST['emergency_name'] ?? ''),
        'emergency_contact_number' => normalizePhoneNumber11($_POST['emergency_number'] ?? ''),
    ];
    $extensionInput = trim($_POST['extension_name'] ?? '');
    $currentPass = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_new_password'] ?? '';

    if (empty($g['last_name'])) $errors[] = 'Last name is required.';
    if (empty($g['contact_number'])) $errors[] = 'Contact number is required.';
    if (empty($g['relationship_to_student'])) $errors[] = 'Relationship to student is required.';
    if (!isValidNameExtension($extensionInput)) $errors[] = nameExtensionErrorMessage();
    if (!isValidPhoneNumber11($g['contact_number'], true)) $errors[] = phoneNumberErrorMessage('Contact number');
    if (!isValidPhoneNumber11($g['emergency_contact_number'])) $errors[] = phoneNumberErrorMessage('Emergency contact number');

    if ($hasPassword && empty($currentPass)) {
        $errors[] = 'Current password is required to save changes.';
    } elseif ($hasPassword && !password_verify($currentPass, $user['password_hash'])) {
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

    if (empty($errors)) {
        $oldData = [
            'first_name'  => $guardian['first_name'],
            'middle_name' => $guardian['middle_name'] ?? '',
            'last_name'   => $guardian['last_name'],
            'extension_name' => $guardian['extension_name'] ?? '',
            'contact'     => $guardian['contact_number'],
            'address'     => $guardian['address'],
        ];

        try {
            $pdo->beginTransaction();
            $pdo->prepare("
                UPDATE guardians
                SET first_name = :fn,
                    middle_name = :mn,
                    last_name = :ln,
                    extension_name = :ext,
                    contact_number = :contact,
                    address = :addr,
                    relationship_to_student = :rel,
                    occupation = :occ,
                    civil_status = :cs,
                    nationality = :nat,
                    religion = :religion,
                    emergency_contact_name = :en,
                    emergency_contact_number = :ec
                WHERE user_id = :uid
            ")->execute([
                ':fn'       => $g['first_name'],
                ':mn'       => $g['middle_name'],
                ':ln'       => $g['last_name'],
                ':ext'      => nullIfBlank($g['extension_name']),
                ':contact'  => $g['contact_number'],
                ':addr'     => nullIfBlank($g['address']),
                ':rel'      => $g['relationship_to_student'],
                ':occ'      => nullIfBlank($g['occupation']),
                ':cs'       => nullIfBlank($g['civil_status']),
                ':nat'      => $g['nationality'],
                ':religion' => nullIfBlank($g['religion']),
                ':en'       => nullIfBlank($g['emergency_contact_name']),
                ':ec'       => nullIfBlank($g['emergency_contact_number']),
                ':uid'      => $userId,
            ]);

            if ($newPassword !== '') {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :uid")
                    ->execute([':hash' => $hash, ':uid' => $userId]);
            }

            $newData = [
                'first_name' => $g['first_name'],
                'middle_name' => $g['middle_name'],
                'last_name' => $g['last_name'],
                'extension_name' => $g['extension_name'],
                'contact' => $g['contact_number'],
                'address' => $g['address'],
            ];
            auditLog('profile_update', 'guardians', $guardian['id'], $oldData, $newData);
            $pdo->commit();
            setFlash('success', 'Profile updated successfully.');
            redirect(APP_URL . '/guardian/profile-view.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            logException($e, 'Guardian profile update failed.');
            $errors[] = safeErrorMessage('Profile could not be saved. Please review the form and try again.');
        }
    }
}

$pageTitle = 'Edit Profile';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold"><i class="bi bi-pencil me-2"></i>Edit Profile</h4>
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

    <form method="POST" action="" id="profile-edit-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

        <h6 class="fw-semibold text-primary mb-3">Personal Information</h6>
        <div class="row">
            <div class="col-md-3 mb-3">
                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="last_name"
                       value="<?= e($g['last_name'] ?? '') ?>" required>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">First Name</label>
                <input type="text" class="form-control" name="first_name"
                       value="<?= e($g['first_name'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Middle Name</label>
                <input type="text" class="form-control" name="middle_name"
                       value="<?= e($g['middle_name'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Extension</label>
                <select class="form-select" name="extension_name">
                    <?php foreach (nameExtensionOptions() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= e(normalizeNameExtension($g['extension_name'] ?? '') === $value ? 'selected' : '') ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Email Address</label>
                <input type="email" class="form-control" value="<?= e($user['email']) ?>" disabled>
                <div class="form-text">Email cannot be changed.</div>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                <input class="form-control" name="contact"
                       value="<?= e($g['contact_number'] ?? '') ?>" <?= phoneInputAttributes(true) ?>>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Relationship to Student <span class="text-danger">*</span></label>
                <select class="form-select" name="relationship" required>
                    <option value="">Select...</option>
                    <?php foreach (['Parent','Guardian','Sibling','Grandparent','Aunt/Uncle','Other'] as $r): ?>
                        <option value="<?= e($r) ?>" <?= e(($g['relationship_to_student'] ?? '') === $r ? 'selected' : '') ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 mb-3">
                <label class="form-label">Home Address</label>
                <textarea class="form-control" name="address" rows="2"><?= e($g['address'] ?? '') ?></textarea>
            </div>
        </div>

        <h6 class="fw-semibold text-primary mb-3 mt-2">Additional Details</h6>
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Occupation</label>
                <input type="text" class="form-control" name="occupation"
                       value="<?= e($g['occupation'] ?? '') ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Civil Status</label>
                <select class="form-select" name="civil_status">
                    <option value="">Select...</option>
                    <?php foreach (['single','married','widowed','separated','others'] as $cs): ?>
                        <option value="<?= e($cs) ?>" <?= e(($g['civil_status'] ?? '') === $cs ? 'selected' : '') ?>><?= e(ucfirst($cs)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Nationality</label>
                <input type="text" class="form-control" name="nationality"
                       value="<?= e($g['nationality'] ?? 'Filipino') ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Religion</label>
                <input type="text" class="form-control" name="religion"
                       value="<?= e($g['religion'] ?? '') ?>">
            </div>
        </div>

        <h6 class="fw-semibold text-primary mb-3 mt-2">Emergency Contact</h6>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Emergency Contact Name</label>
                <input type="text" class="form-control" name="emergency_name"
                       value="<?= e($g['emergency_contact_name'] ?? '') ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Emergency Contact Number</label>
                <input class="form-control" name="emergency_number"
                       value="<?= e($g['emergency_contact_number'] ?? '') ?>" <?= phoneInputAttributes() ?>>
            </div>
        </div>

        <hr>

        <h6 class="fw-semibold text-primary mb-3">Password</h6>
        <?php if ($hasPassword): ?>
        <div class="mb-3">
            <label class="form-label">Current Password <span class="text-danger">*</span></label>
            <input type="password" class="form-control" name="current_password" required>
            <div class="form-text">Required to confirm any changes.</div>
        </div>
        <?php endif; ?>
        <div class="mb-4">
            <label class="form-label">New Password <small class="text-muted">(optional)</small></label>
            <input type="password" class="form-control" name="new_password" minlength="8">
            <div class="form-text">Leave blank to keep current password. Min 8 characters.</div>
        </div>
        <div class="mb-4">
            <label class="form-label">Confirm New Password</label>
            <input type="password" class="form-control" name="confirm_new_password" minlength="8">
        </div>

        <div class="d-flex justify-content-between">
            <a href="<?= APP_URL ?>/guardian/profile-view.php" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Save Changes</button>
        </div>
    </form>
</div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
