<?php
/**
 * Enrollment Page — Multi-step enrollment form
 * Step 1: Student details
 * Step 2: Grade level / Section selection
 * Step 3: Payment method & fee breakdown
 * Step 4: Confirmation
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$userId = $_SESSION['user_id'];

// Get guardian
$stmt = $pdo->prepare("SELECT * FROM guardians WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$guardian = $stmt->fetch();

if (!$guardian) {
    setFlash('danger', 'Guardian profile not found. Please complete your profile first.');
    redirect(APP_URL . '/auth/complete-profile.php');
}

$step   = (int)($_POST['step'] ?? $_GET['step'] ?? 1);
$errors = [];

// Get sections for dropdown
$sections = $pdo->query("SELECT id, name, grade_level, capacity FROM sections ORDER BY grade_level, name")->fetchAll();

// ── Handle form submissions ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    if ($step === 2) {
        // Save Step 1 data to session
        $_SESSION['enroll'] = [
            'full_name' => trim($_POST['full_name'] ?? ''),
            'birthdate' => trim($_POST['birthdate'] ?? ''),
            'gender'    => trim($_POST['gender'] ?? ''),
            'lrn'       => trim($_POST['lrn'] ?? ''),
        ];
        if (empty($_SESSION['enroll']['full_name'])) $errors[] = 'Student name is required.';
        if (empty($errors)) $step = 2; else $step = 1;
    } elseif ($step === 3) {
        $_SESSION['enroll']['grade_level'] = trim($_POST['grade_level'] ?? '');
        $_SESSION['enroll']['section_id']  = (int)($_POST['section_id'] ?? 0);
        $_SESSION['enroll']['school_year'] = trim($_POST['school_year'] ?? currentSchoolYear());
        $_SESSION['enroll']['term']        = trim($_POST['term'] ?? '1st Semester');
        if (empty($_SESSION['enroll']['grade_level'])) $errors[] = 'Grade level is required.';
        if (empty($errors)) $step = 3; else $step = 2;
    } elseif ($step === 4) {
        $_SESSION['enroll']['payment_method'] = trim($_POST['payment_method'] ?? 'cash');
        $step = 4;
    } elseif ($step === 5) {
        // Final submission
        $en = $_SESSION['enroll'] ?? [];
        if (empty($en['full_name'])) {
            $errors[] = 'Enrollment data is incomplete. Please start over.';
            $step = 1;
        } else {
            try {
                $pdo->beginTransaction();

                // Create student
                $stmt = $pdo->prepare("
                    INSERT INTO students (guardian_id, full_name, birthdate, gender, grade_level, section_id, lrn)
                    VALUES (:gid, :name, :birth, :gender, :grade, :sec, :lrn)
                ");
                $stmt->execute([
                    ':gid'    => $guardian['id'],
                    ':name'   => $en['full_name'],
                    ':birth'  => $en['birthdate'] ?: null,
                    ':gender' => $en['gender'] ?: null,
                    ':grade'  => $en['grade_level'],
                    ':sec'    => $en['section_id'] ?: null,
                    ':lrn'    => $en['lrn'] ?: null,
                ]);
                $studentId = $pdo->lastInsertId();

                // Create enrollment
                $stmt = $pdo->prepare("
                    INSERT INTO enrollments (student_id, school_year, term, status)
                    VALUES (:sid, :sy, :term, 'pending')
                ");
                $stmt->execute([
                    ':sid'  => $studentId,
                    ':sy'   => $en['school_year'] ?? currentSchoolYear(),
                    ':term' => $en['term'] ?? '1st Semester',
                ]);
                $enrollmentId = $pdo->lastInsertId();

                // Create pending payment
                $tuitionFee = 15000.00; // fixed enrollment fee
                $stmt = $pdo->prepare("
                    INSERT INTO payments (enrollment_id, amount, method, description, status)
                    VALUES (:eid, :amt, :method, 'Enrollment Fee', 'pending')
                ");
                $stmt->execute([
                    ':eid'    => $enrollmentId,
                    ':amt'    => $tuitionFee,
                    ':method' => $en['payment_method'] ?? 'cash',
                ]);

                $pdo->commit();
                unset($_SESSION['enroll']);

                auditLog('enrollment_submitted', 'enrollments', (int)$enrollmentId);
                setFlash('success', 'Enrollment application submitted successfully! Your application is now pending review.');
                redirect(APP_URL . '/guardian/dashboard.php');
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Enrollment error: ' . $e->getMessage());
                $errors[] = 'An error occurred. Please try again.';
                $step = 4;
            }
        }
    }
}

$enrollData = $_SESSION['enroll'] ?? [];
$pageTitle  = 'Enrollment';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold"><i class="bi bi-pencil-square me-2"></i>Student Enrollment</h4>
    </div>
</div>

<!-- Step Indicator -->
<div class="step-indicator mb-4">
    <div class="step <?= $step >= 1 ? ($step > 1 ? 'completed' : 'active') : '' ?>">
        <span class="step-number"><?= $step > 1 ? '✓' : '1' ?></span>
        <span>Student Info</span>
    </div>
    <div class="step-line"></div>
    <div class="step <?= $step >= 2 ? ($step > 2 ? 'completed' : 'active') : '' ?>">
        <span class="step-number"><?= $step > 2 ? '✓' : '2' ?></span>
        <span>Grade & Section</span>
    </div>
    <div class="step-line"></div>
    <div class="step <?= $step >= 3 ? ($step > 3 ? 'completed' : 'active') : '' ?>">
        <span class="step-number"><?= $step > 3 ? '✓' : '3' ?></span>
        <span>Payment</span>
    </div>
    <div class="step-line"></div>
    <div class="step <?= $step >= 4 ? 'active' : '' ?>">
        <span class="step-number">4</span>
        <span>Confirmation</span>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card">
<div class="card-body p-4">

<?php if ($step === 1): ?>
<!-- STEP 1: Student Details -->
<h5 class="fw-bold mb-3">Step 1: Student Information</h5>
<form method="POST" action="" id="enrollment-step1">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="step" value="2">

    <div class="mb-3">
        <label for="full_name" class="form-label">Student Full Name <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="full_name" name="full_name" value="<?= e($enrollData['full_name'] ?? '') ?>" required>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="birthdate" class="form-label">Birthdate</label>
            <input type="date" class="form-control" id="birthdate" name="birthdate" value="<?= e($enrollData['birthdate'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
            <label for="gender" class="form-label">Gender</label>
            <select class="form-select" id="gender" name="gender">
                <option value="">Select...</option>
                <option value="male" <?= ($enrollData['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                <option value="female" <?= ($enrollData['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                <option value="other" <?= ($enrollData['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
            </select>
        </div>
    </div>

    <div class="mb-3">
        <label for="lrn" class="form-label">Learner Reference Number (LRN)</label>
        <input type="text" class="form-control" id="lrn" name="lrn" value="<?= e($enrollData['lrn'] ?? '') ?>">
    </div>

    <button type="submit" class="btn btn-primary">Next: Grade & Section <i class="bi bi-arrow-right ms-1"></i></button>
</form>

<?php elseif ($step === 2): ?>
<!-- STEP 2: Grade Level & Section -->
<h5 class="fw-bold mb-3">Step 2: Grade Level & Section</h5>
<form method="POST" action="" id="enrollment-step2">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="step" value="3">

    <div class="mb-3">
        <label for="grade_level" class="form-label">Grade Level <span class="text-danger">*</span></label>
        <select class="form-select" id="grade_level" name="grade_level" required>
            <option value="">Select...</option>
            <?php for ($g = 7; $g <= 12; $g++): ?>
                <option value="<?= $g ?>" <?= ($enrollData['grade_level'] ?? '') == $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
            <?php endfor; ?>
        </select>
    </div>

    <div class="mb-3">
        <label for="section_id" class="form-label">Section</label>
        <select class="form-select" id="section_id" name="section_id">
            <option value="0">To be assigned</option>
            <?php foreach ($sections as $sec): ?>
                <option value="<?= $sec['id'] ?>" <?= ($enrollData['section_id'] ?? 0) == $sec['id'] ? 'selected' : '' ?>>
                    <?= e($sec['name']) ?> (Grade <?= e($sec['grade_level']) ?>, Capacity: <?= $sec['capacity'] ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="school_year" class="form-label">School Year</label>
            <input type="text" class="form-control" id="school_year" name="school_year" value="<?= e($enrollData['school_year'] ?? currentSchoolYear()) ?>" readonly>
        </div>
        <div class="col-md-6 mb-3">
            <label for="term" class="form-label">Term</label>
            <select class="form-select" id="term" name="term">
                <option value="1st Semester">1st Semester</option>
                <option value="2nd Semester">2nd Semester</option>
            </select>
        </div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="?step=1" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
        <button type="submit" class="btn btn-primary">Next: Payment <i class="bi bi-arrow-right ms-1"></i></button>
    </div>
</form>

<?php elseif ($step === 3): ?>
<!-- STEP 3: Payment -->
<h5 class="fw-bold mb-3">Step 3: Payment Method & Fee Breakdown</h5>
<form method="POST" action="" id="enrollment-step3">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="step" value="4">

    <div class="card bg-light mb-3">
        <div class="card-body">
            <h6 class="fw-bold">Fee Breakdown</h6>
            <table class="table table-sm mb-0">
                <tr><td>Tuition Fee</td><td class="text-end">₱12,000.00</td></tr>
                <tr><td>Miscellaneous Fee</td><td class="text-end">₱2,000.00</td></tr>
                <tr><td>Lab Fee</td><td class="text-end">₱1,000.00</td></tr>
                <tr class="fw-bold border-top"><td>Total</td><td class="text-end">₱15,000.00</td></tr>
            </table>
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label fw-bold">Payment Method <span class="text-danger">*</span></label>
        <div class="form-check">
            <input class="form-check-input" type="radio" name="payment_method" id="pay_cash" value="cash" checked>
            <label class="form-check-label" for="pay_cash"><i class="bi bi-cash me-1"></i>Cash (Pay at the cashier)</label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="radio" name="payment_method" id="pay_online" value="online">
            <label class="form-check-label" for="pay_online"><i class="bi bi-phone me-1"></i>Online Payment</label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="radio" name="payment_method" id="pay_bank" value="bank">
            <label class="form-check-label" for="pay_bank"><i class="bi bi-bank me-1"></i>Bank Transfer</label>
        </div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="?step=2" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
        <button type="submit" class="btn btn-primary">Next: Review <i class="bi bi-arrow-right ms-1"></i></button>
    </div>
</form>

<?php elseif ($step === 4): ?>
<!-- STEP 4: Confirmation -->
<h5 class="fw-bold mb-3">Step 4: Review & Confirm</h5>
<div class="table-responsive mb-3">
    <table class="table table-bordered">
        <tr><th class="bg-light" width="30%">Student Name</th><td><?= e($enrollData['full_name'] ?? '') ?></td></tr>
        <tr><th class="bg-light">Birthdate</th><td><?= e($enrollData['birthdate'] ?? 'N/A') ?></td></tr>
        <tr><th class="bg-light">Gender</th><td><?= e(ucfirst($enrollData['gender'] ?? 'N/A')) ?></td></tr>
        <tr><th class="bg-light">LRN</th><td><?= e($enrollData['lrn'] ?? 'N/A') ?></td></tr>
        <tr><th class="bg-light">Grade Level</th><td>Grade <?= e($enrollData['grade_level'] ?? '') ?></td></tr>
        <tr><th class="bg-light">School Year</th><td><?= e($enrollData['school_year'] ?? currentSchoolYear()) ?></td></tr>
        <tr><th class="bg-light">Term</th><td><?= e($enrollData['term'] ?? '1st Semester') ?></td></tr>
        <tr><th class="bg-light">Payment Method</th><td><?= e(ucfirst($enrollData['payment_method'] ?? 'cash')) ?></td></tr>
        <tr class="fw-bold"><th class="bg-light">Total Fee</th><td>₱15,000.00</td></tr>
    </table>
</div>

<form method="POST" action="" id="enrollment-confirm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="step" value="5">

    <div class="alert alert-info">
        <i class="bi bi-info-circle me-1"></i>
        By submitting, your enrollment application will be sent for review. You will receive a confirmation once approved.
    </div>

    <div class="d-flex justify-content-between">
        <a href="?step=3" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
        <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i>Submit Enrollment</button>
    </div>
</form>
<?php endif; ?>

</div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
