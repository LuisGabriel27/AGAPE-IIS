<?php
/**
 * Complete Profile â€” Post-Google Signup
 * New Google users must fill in guardian details before entering the system.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

// Only show this page if profile needs completion
if (empty($_SESSION['needs_profile_completion'])) {
    redirect(APP_URL . '/guardian/dashboard.php');
}

$errors   = [];
$formData = ['contact' => '', 'address' => '', 'relationship' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $formData['contact']      = trim($_POST['contact'] ?? '');
    $formData['address']      = trim($_POST['address'] ?? '');
    $formData['relationship'] = trim($_POST['relationship'] ?? '');

    if (empty($formData['contact']))      $errors[] = 'Contact number is required.';
    if (empty($formData['address']))       $errors[] = 'Address is required.';
    if (empty($formData['relationship']))  $errors[] = 'Relationship to student is required.';

    if (empty($errors)) {
        $pdo  = getDB();
        $stmt = $pdo->prepare("
            UPDATE guardians SET contact_number = :contact, address = :addr, relationship_to_student = :rel
            WHERE user_id = :uid
        ");
        $stmt->execute([
            ':contact' => $formData['contact'],
            ':addr'    => $formData['address'],
            ':rel'     => $formData['relationship'],
            ':uid'     => $_SESSION['user_id'],
        ]);

        unset($_SESSION['needs_profile_completion']);
        auditLog('profile_complete', 'guardians', $_SESSION['user_id']);
        setFlash('success', 'Profile completed successfully! Welcome to the Academy.');
        redirect(APP_URL . '/guardian/dashboard.php');
    }
}

$pageTitle = 'Complete Your Profile';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-6 col-md-8">
        <div class="card">
            <div class="card-body p-4">
                <div class="text-center mb-4">
                    <?php if (!empty($_SESSION['google_avatar'])): ?>
                        <img src="<?= e($_SESSION['google_avatar']) ?>" class="rounded-circle mb-2" width="64" height="64" alt="Avatar">
                    <?php endif; ?>
                    <h4 class="fw-bold">Complete Your Profile</h4>
                    <p class="text-muted small">Please fill in the remaining details to continue.</p>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger py-2">
                        <?php foreach ($errors as $err): ?>
                            <div><small><?= e($err) ?></small></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="complete-profile-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

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
                            <option value="Parent" <?= e($formData['relationship'] === 'Parent' ? 'selected' : '') ?>>Parent</option>
                            <option value="Guardian" <?= e($formData['relationship'] === 'Guardian' ? 'selected' : '') ?>>Guardian</option>
                            <option value="Sibling" <?= e($formData['relationship'] === 'Sibling' ? 'selected' : '') ?>>Sibling</option>
                            <option value="Other" <?= e($formData['relationship'] === 'Other' ? 'selected' : '') ?>>Other</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary w-100" id="btn-complete-profile">
                        <i class="bi bi-check-circle me-1"></i>Complete Profile
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

