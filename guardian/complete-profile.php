<?php
/**
 * Complete Profile — Post-registration setup for new guardians.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

if (empty($_SESSION['needs_profile_completion'])) {
    redirect(APP_URL . '/guardian/dashboard.php');
}

$pdo = getDB();
$guardian = getOrCreateGuardianProfile($pdo, (int)($_SESSION['user_id'] ?? 0));
if (!$guardian) {
    setFlash('danger', 'Guardian profile could not be prepared. Please contact the administrator.');
    redirect(APP_URL . '/guardian/dashboard.php');
}

$errors   = [];
$formData = [
    'middle_name'      => '',
    'contact'          => '',
    'address'          => '',
    'relationship'     => '',
    'occupation'       => '',
    'civil_status'     => '',
    'nationality'      => 'Filipino',
    'religion'         => '',
    'emergency_name'   => '',
    'emergency_number' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    foreach ($formData as $k => $_) {
        $formData[$k] = trim($_POST[$k] ?? '');
    }
    $formData['contact'] = normalizePhoneNumber11($formData['contact']);
    $formData['emergency_number'] = normalizePhoneNumber11($formData['emergency_number']);
    $formData['nationality'] = $formData['nationality'] ?: 'Filipino';

    if (empty($formData['contact']))      $errors[] = 'Contact number is required.';
    if (empty($formData['address']))      $errors[] = 'Address is required.';
    if (empty($formData['relationship'])) $errors[] = 'Relationship to student is required.';
    if (!isValidPhoneNumber11($formData['contact'], true)) $errors[] = phoneNumberErrorMessage('Contact number');
    if (!isValidPhoneNumber11($formData['emergency_number'])) $errors[] = phoneNumberErrorMessage('Emergency contact number');

    if (empty($errors)) {
        $pdo->prepare("
            UPDATE guardians
            SET middle_name = :mn, contact_number = :contact, address = :addr,
                relationship_to_student = :rel, occupation = :occ,
                civil_status = :cs, nationality = :nat, religion = :religion,
                emergency_contact_name = :en, emergency_contact_number = :ec,
                data_privacy_consent = TRUE,
                data_privacy_consented_at = COALESCE(data_privacy_consented_at, NOW())
            WHERE user_id = :uid
        ")->execute([
            ':mn'       => $formData['middle_name'],
            ':contact'  => $formData['contact'],
            ':addr'     => $formData['address'],
            ':rel'      => $formData['relationship'],
            ':occ'      => $formData['occupation'] ?: null,
            ':cs'       => $formData['civil_status'] ?: null,
            ':nat'      => $formData['nationality'],
            ':religion' => $formData['religion'] ?: null,
            ':en'       => $formData['emergency_name'] ?: null,
            ':ec'       => $formData['emergency_number'] ?: null,
            ':uid'      => $_SESSION['user_id'],
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
<div class="col-lg-8 col-md-10">
<div class="card">
<div class="card-body p-4">
    <div class="text-center mb-4">
        <?php if (!empty($_SESSION['google_avatar'])): ?>
            <img src="<?= e($_SESSION['google_avatar']) ?>" class="rounded-circle mb-2" width="64" height="64" alt="Avatar">
        <?php endif; ?>
        <h4 class="fw-bold">Complete Your Profile</h4>
        <p class="text-muted small">Please fill in your guardian details before enrolling a student.</p>
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

        <h6 class="fw-semibold text-primary mb-3">Contact Information</h6>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Middle Name</label>
                <input type="text" class="form-control" name="middle_name" value="<?= e($formData['middle_name']) ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                <input class="form-control" name="contact" value="<?= e($formData['contact']) ?>" <?= phoneInputAttributes(true) ?>>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Relationship to Student <span class="text-danger">*</span></label>
                <select class="form-select" name="relationship" required>
                    <option value="">Select...</option>
                    <?php foreach (['Parent','Guardian','Sibling','Grandparent','Aunt/Uncle','Other'] as $r): ?>
                        <option value="<?= e($r) ?>" <?= e($formData['relationship'] === $r ? 'selected' : '') ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 mb-3">
                <label class="form-label">Home Address <span class="text-danger">*</span></label>
                <textarea class="form-control" name="address" rows="2" required><?= e($formData['address']) ?></textarea>
            </div>
        </div>

        <h6 class="fw-semibold text-primary mb-3 mt-2">Additional Details</h6>
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Occupation</label>
                <input type="text" class="form-control" name="occupation" value="<?= e($formData['occupation']) ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Civil Status</label>
                <select class="form-select" name="civil_status">
                    <option value="">Select...</option>
                    <?php foreach (['single','married','widowed','separated','others'] as $cs): ?>
                        <option value="<?= e($cs) ?>" <?= e($formData['civil_status'] === $cs ? 'selected' : '') ?>><?= e(ucfirst($cs)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Nationality</label>
                <input type="text" class="form-control" name="nationality" value="<?= e($formData['nationality']) ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Religion</label>
                <input type="text" class="form-control" name="religion" value="<?= e($formData['religion']) ?>">
            </div>
        </div>

        <h6 class="fw-semibold text-primary mb-3 mt-2">Emergency Contact</h6>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Emergency Contact Name</label>
                <input type="text" class="form-control" name="emergency_name" value="<?= e($formData['emergency_name']) ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Emergency Contact Number</label>
                <input class="form-control" name="emergency_number" value="<?= e($formData['emergency_number']) ?>" <?= phoneInputAttributes() ?>>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100" id="btn-complete-profile">
            <i class="bi bi-check-circle me-1"></i>Complete Profile &amp; Continue
        </button>
    </form>
</div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
