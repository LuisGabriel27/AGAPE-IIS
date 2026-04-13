<?php
/**
 * Guardian Profile Edit
 * Editable form for guardian personal info with password confirmation (email/password accounts).
 * Accounts without a password can set one here.
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

$stmt = $pdo->prepare("SELECT * FROM guardians WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$guardian = $stmt->fetch();

$hasPassword = !empty($user['password_hash']);

$errors   = [];
$success  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $fullName    = trim($_POST['full_name'] ?? '');
    $contact     = trim($_POST['contact'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $relationship = trim($_POST['relationship'] ?? '');
    $currentPass = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';

    if (empty($fullName)) $errors[] = 'Full name is required.';
    if (empty($contact))  $errors[] = 'Contact number is required.';

    // Password confirmation required for email/password accounts
    if ($hasPassword && empty($currentPass)) {
        $errors[] = 'Current password is required to save changes.';
    } elseif ($hasPassword && !password_verify($currentPass, $user['password_hash'])) {
        $errors[] = 'Current password is incorrect.';
    }

    if (empty($errors)) {
        $oldData = [
            'full_name' => $guardian['full_name'],
            'contact'   => $guardian['contact_number'],
            'address'   => $guardian['address'],
        ];

        $stmt = $pdo->prepare("
            UPDATE guardians SET full_name = :name, contact_number = :contact, address = :addr, relationship_to_student = :rel
            WHERE user_id = :uid
        ");
        $stmt->execute([
            ':name'    => $fullName,
            ':contact' => $contact,
            ':addr'    => $address,
            ':rel'     => $relationship,
            ':uid'     => $userId,
        ]);

        $newData = ['full_name' => $fullName, 'contact' => $contact, 'address' => $address];

        // Optional: set or update password
        if (!empty($newPassword)) {
            if (strlen($newPassword) < 8) {
                $errors[] = 'New password must be at least 8 characters.';
            } else {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :uid");
                $stmt->execute([':hash' => $hash, ':uid' => $userId]);
            }
        }

        if (empty($errors)) {
            auditLog('profile_update', 'guardians', $guardian['id'], $oldData, $newData);
            setFlash('success', 'Profile updated successfully.');
            redirect(APP_URL . '/guardian/profile-view.php');
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
<div class="col-lg-8">
<div class="card">
<div class="card-body p-4">

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger py-2">
            <?php foreach ($errors as $err): ?><div><small><?= e($err) ?></small></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="" id="profile-edit-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

        <div class="mb-3">
            <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="full_name" name="full_name" value="<?= e($guardian['full_name'] ?? '') ?>" required>
        </div>

        <div class="mb-3">
            <label for="email" class="form-label">Email Address</label>
            <input type="email" class="form-control" value="<?= e($user['email']) ?>" disabled>
            <div class="form-text">Email cannot be changed.</div>
        </div>

        <div class="mb-3">
            <label for="contact" class="form-label">Contact Number <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="contact" name="contact" value="<?= e($guardian['contact_number'] ?? '') ?>" required>
        </div>

        <div class="mb-3">
            <label for="address" class="form-label">Address</label>
            <textarea class="form-control" id="address" name="address" rows="2"><?= e($guardian['address'] ?? '') ?></textarea>
        </div>

        <div class="mb-3">
            <label for="relationship" class="form-label">Relationship to Student</label>
            <select class="form-select" id="relationship" name="relationship">
                <option value="">Select...</option>
                <?php foreach (['Parent','Guardian','Sibling','Other'] as $r): ?>
                    <option value="<?= e($r) ?>" <?= e(($guardian['relationship_to_student'] ?? '') === $r ? 'selected' : '') ?>><?= e($r) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <hr>

        <?php if ($hasPassword): ?>
        <div class="mb-3">
            <label for="current_password" class="form-label">Current Password <span class="text-danger">*</span></label>
            <input type="password" class="form-control" id="current_password" name="current_password" required>
            <div class="form-text">Required to confirm changes.</div>
        </div>
        <?php endif; ?>

        <div class="mb-3">
            <label for="new_password" class="form-label">New Password <small class="text-muted">(optional)</small></label>
            <input type="password" class="form-control" id="new_password" name="new_password" minlength="8">
            <div class="form-text">Leave blank to keep current password. Min 8 characters.</div>
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

