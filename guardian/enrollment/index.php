<?php
/**
 * Guardian Enrollment — Combined Registration
 * Step 1: Guardian Profile (skip if already complete)
 * Step 2: Student Information
 * Step 3: Enrollment Details → Payment
 */

require_once __DIR__ . '/../../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/helpers.php';

$pdo    = getDB();
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM guardians WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$guardian = $stmt->fetch();

if (!$guardian) {
    setFlash('danger', 'Guardian profile not found. Please complete your profile first.');
    redirect(APP_URL . '/guardian/complete-profile.php');
}

// Determine if guardian profile is considered complete
$profileComplete = !empty($guardian['full_name'])
    && !empty($guardian['contact_number'])
    && !empty($guardian['relationship_to_student']);

$step    = (int)($_POST['step'] ?? $_GET['step'] ?? ($profileComplete ? 2 : 1));
$errors  = [];
$sections = $pdo->query("SELECT id, name, grade_level, capacity FROM sections ORDER BY grade_level, name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    // ── STEP 1 → 2: Save guardian profile ─────────────────
    if ($step === 2) {
        $gFullName    = trim($_POST['g_full_name'] ?? '');
        $gContact     = trim($_POST['g_contact'] ?? '');
        $gAddress     = trim($_POST['g_address'] ?? '');
        $gRel         = trim($_POST['g_relationship'] ?? '');
        $gOccupation  = trim($_POST['g_occupation'] ?? '');
        $gCivilStatus = trim($_POST['g_civil_status'] ?? '');
        $gNationality = trim($_POST['g_nationality'] ?? 'Filipino');
        $gReligion    = trim($_POST['g_religion'] ?? '');
        $gEmergName   = trim($_POST['g_emergency_name'] ?? '');
        $gEmergNum    = trim($_POST['g_emergency_number'] ?? '');

        if (empty($gFullName))  $errors[] = 'Your full name is required.';
        if (empty($gContact))   $errors[] = 'Contact number is required.';
        if (empty($gRel))       $errors[] = 'Relationship to student is required.';

        if (empty($errors)) {
            $pdo->prepare("
                UPDATE guardians
                SET full_name = :name, contact_number = :contact, address = :addr,
                    relationship_to_student = :rel, occupation = :occ,
                    civil_status = :cs, nationality = :nat, religion = :rel2,
                    emergency_contact_name = :en, emergency_contact_number = :ec
                WHERE user_id = :uid
            ")->execute([
                ':name'    => $gFullName,
                ':contact' => $gContact,
                ':addr'    => $gAddress ?: null,
                ':rel'     => $gRel,
                ':occ'     => $gOccupation ?: null,
                ':cs'      => $gCivilStatus ?: null,
                ':nat'     => $gNationality,
                ':rel2'    => $gReligion ?: null,
                ':en'      => $gEmergName ?: null,
                ':ec'      => $gEmergNum ?: null,
                ':uid'     => $userId,
            ]);
            // Re-fetch updated guardian
            $stmt = $pdo->prepare("SELECT * FROM guardians WHERE user_id = :uid LIMIT 1");
            $stmt->execute([':uid' => $userId]);
            $guardian = $stmt->fetch();
        } else {
            $step = 1;
        }
    }

    // ── STEP 2 → 3: Save student info in session ──────────
    if ($step === 3 && empty($errors)) {
        $_SESSION['enroll'] = [
            'full_name' => trim($_POST['full_name'] ?? ''),
            'birthdate' => trim($_POST['birthdate'] ?? ''),
            'gender'    => trim($_POST['gender'] ?? ''),
            'lrn'       => trim($_POST['lrn'] ?? ''),
        ];

        if (empty($_SESSION['enroll']['full_name'])) {
            $errors[] = 'Student name is required.';
            $step = 2;
        }
    }

    // ── STEP 3 → Submit: Create records ───────────────────
    if ($step === 4 && empty($errors)) {
        $_SESSION['enroll']['grade_level'] = trim($_POST['grade_level'] ?? '');
        $_SESSION['enroll']['section_id']  = (int)($_POST['section_id'] ?? 0);
        $_SESSION['enroll']['school_year'] = trim($_POST['school_year'] ?? currentSchoolYear());
        $_SESSION['enroll']['term']        = trim($_POST['term'] ?? '1st Semester');

        $enrollData = $_SESSION['enroll'] ?? [];
        if (empty($enrollData['full_name']) || empty($enrollData['grade_level'])) {
            $errors[] = 'Enrollment data is incomplete.';
            $step = 2;
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO students (guardian_id, full_name, birthdate, gender, grade_level, section_id, lrn)
                    VALUES (:gid, :name, :birth, :gender, :grade, :sec, :lrn) RETURNING id
                ");
                $stmt->execute([
                    ':gid'    => $guardian['id'],
                    ':name'   => $enrollData['full_name'],
                    ':birth'  => $enrollData['birthdate'] ?: null,
                    ':gender' => $enrollData['gender'] ?: null,
                    ':grade'  => $enrollData['grade_level'],
                    ':sec'    => $enrollData['section_id'] ?: null,
                    ':lrn'    => $enrollData['lrn'] ?: null,
                ]);
                $studentId = (int)$stmt->fetchColumn();

                $stmt = $pdo->prepare("
                    INSERT INTO enrollments (student_id, school_year, term, status, payment_submitted_at)
                    VALUES (:sid, :sy, :term, 'pending', NULL) RETURNING id
                ");
                $stmt->execute([
                    ':sid'  => $studentId,
                    ':sy'   => $enrollData['school_year'],
                    ':term' => $enrollData['term'],
                ]);
                $enrollmentId = (int)$stmt->fetchColumn();

                $pdo->prepare("
                    INSERT INTO payments (enrollment_id, amount, method, description, status)
                    VALUES (:eid, 15000.00, 'cash', 'Enrollment Fee', 'pending')
                ")->execute([':eid' => $enrollmentId]);

                $pdo->commit();
                unset($_SESSION['enroll']);

                auditLog('enrollment_submitted', 'enrollments', $enrollmentId, null, [
                    'student_id'  => $studentId,
                    'school_year' => $enrollData['school_year'],
                    'term'        => $enrollData['term'],
                ]);

                setFlash('success', 'Enrollment submitted. Complete the payment form to finalize your application.');
                redirect(APP_URL . '/guardian/enrollment/payment.php?enrollment_id=' . $enrollmentId);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Enrollment creation error: ' . $e->getMessage());
                $errors[] = 'An error occurred while saving enrollment. Please try again.';
                $step = 3;
            }
        }
    }
}

$enrollData = $_SESSION['enroll'] ?? [];
$totalSteps = $profileComplete ? 3 : 4; // if profile is already done, skip step 1 visually
$pageTitle  = 'Enrollment';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h4 class="fw-bold mb-0"><i class="bi bi-pencil-square me-2"></i>Student Enrollment</h4>
    </div>
    <div class="col-md-4 text-md-end mt-3 mt-md-0">
        <a class="btn btn-outline-primary btn-sm" href="<?= APP_URL ?>/guardian/enrollment/certificate.php" target="_blank" rel="noopener">
            <i class="bi bi-printer me-1"></i>Print Certificate
        </a>
    </div>
</div>

<?php
// Build step labels based on whether profile step is needed
if (!$profileComplete) {
    $stepLabels = ['1' => 'Your Details', '2' => 'Student Info', '3' => 'Enrollment Details', '4' => 'Payment'];
    $displayStep = $step;
} else {
    $stepLabels = ['2' => 'Student Info', '3' => 'Enrollment Details', '4' => 'Payment'];
    $displayStep = $step - 1;
}
$totalDisplaySteps = count($stepLabels);
$stepKeys = array_keys($stepLabels);
?>

<div class="step-indicator mb-4">
    <?php foreach ($stepKeys as $idx => $k):
        $label = $stepLabels[$k];
        $isActive = ((int)$k === $step);
        $isCompleted = ((int)$k < $step);
        $cls = $isActive ? 'active' : ($isCompleted ? 'completed' : '');
    ?>
    <?php if ($idx > 0): ?><div class="step-line"></div><?php endif; ?>
    <div class="step <?= e($cls) ?>">
        <span class="step-number"><?= $isCompleted ? '&#10003;' : e((string)($idx + 1)) ?></span>
        <span><?= e($label) ?></span>
    </div>
    <?php endforeach; ?>
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
    <!-- ────────────────────────────────────────────────── -->
    <!-- STEP 1: Guardian / Parent Details                  -->
    <!-- ────────────────────────────────────────────────── -->
    <h5 class="fw-bold mb-3"><i class="bi bi-person-lines-fill me-2"></i>Step 1: Your Details</h5>
    <p class="text-muted small mb-4">Please complete your guardian profile before enrolling a student. This information is required for school records.</p>
    <form method="POST" action="" id="guardian-profile-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="step" value="2">

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="g_full_name"
                       value="<?= e($guardian['full_name'] ?? '') ?>" required>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="g_contact"
                       value="<?= e($guardian['contact_number'] ?? '') ?>" required>
            </div>
            <div class="col-12 mb-3">
                <label class="form-label">Home Address</label>
                <textarea class="form-control" name="g_address" rows="2"><?= e($guardian['address'] ?? '') ?></textarea>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Relationship to Student <span class="text-danger">*</span></label>
                <select class="form-select" name="g_relationship" required>
                    <option value="">Select...</option>
                    <?php foreach (['Parent','Guardian','Sibling','Grandparent','Aunt/Uncle','Other'] as $r): ?>
                        <option value="<?= e($r) ?>" <?= e(($guardian['relationship_to_student'] ?? '') === $r ? 'selected' : '') ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Occupation</label>
                <input type="text" class="form-control" name="g_occupation"
                       value="<?= e($guardian['occupation'] ?? '') ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Civil Status</label>
                <select class="form-select" name="g_civil_status">
                    <option value="">Select...</option>
                    <?php foreach (['single','married','widowed','separated','others'] as $cs): ?>
                        <option value="<?= e($cs) ?>" <?= e(($guardian['civil_status'] ?? '') === $cs ? 'selected' : '') ?>><?= e(ucfirst($cs)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Nationality</label>
                <input type="text" class="form-control" name="g_nationality"
                       value="<?= e($guardian['nationality'] ?? 'Filipino') ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Religion</label>
                <input type="text" class="form-control" name="g_religion"
                       value="<?= e($guardian['religion'] ?? '') ?>">
            </div>
        </div>

        <h6 class="fw-semibold mb-3 mt-2">Emergency Contact</h6>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Emergency Contact Name</label>
                <input type="text" class="form-control" name="g_emergency_name"
                       value="<?= e($guardian['emergency_contact_name'] ?? '') ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Emergency Contact Number</label>
                <input type="text" class="form-control" name="g_emergency_number"
                       value="<?= e($guardian['emergency_contact_number'] ?? '') ?>">
            </div>
        </div>

        <button type="submit" class="btn btn-primary">
            Save &amp; Continue <i class="bi bi-arrow-right ms-1"></i>
        </button>
    </form>

<?php elseif ($step === 2): ?>
    <!-- ────────────────────────────────────────────────── -->
    <!-- STEP 2: Student Information                        -->
    <!-- ────────────────────────────────────────────────── -->
    <h5 class="fw-bold mb-3"><i class="bi bi-person-badge me-2"></i>Step <?= $profileComplete ? '1' : '2' ?>: Student Information</h5>
    <form method="POST" action="" id="enrollment-step-student">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="step" value="3">

        <div class="mb-3">
            <label class="form-label">Student Full Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="full_name"
                   value="<?= e($enrollData['full_name'] ?? '') ?>" required>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Birthdate</label>
                <input type="date" class="form-control" name="birthdate"
                       value="<?= e($enrollData['birthdate'] ?? '') ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Gender</label>
                <select class="form-select" name="gender">
                    <option value="">Select...</option>
                    <?php foreach (['male','female','other'] as $g): ?>
                        <option value="<?= e($g) ?>" <?= ($enrollData['gender'] ?? '') === $g ? 'selected' : '' ?>><?= e(ucfirst($g)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label">Learner Reference Number (LRN)</label>
            <input type="text" class="form-control" name="lrn"
                   value="<?= e($enrollData['lrn'] ?? '') ?>"
                   maxlength="12" placeholder="12-digit LRN (if available)">
        </div>

        <div class="d-flex gap-2">
            <?php if (!$profileComplete): ?>
                <a href="?step=1" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">
                Next: Enrollment Details <i class="bi bi-arrow-right ms-1"></i>
            </button>
        </div>
    </form>

<?php elseif ($step === 3): ?>
    <!-- ────────────────────────────────────────────────── -->
    <!-- STEP 3: Enrollment Details                         -->
    <!-- ────────────────────────────────────────────────── -->
    <h5 class="fw-bold mb-3"><i class="bi bi-journal-check me-2"></i>Step <?= $profileComplete ? '2' : '3' ?>: Enrollment Details</h5>
    <form method="POST" action="" id="enrollment-step-details">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="step" value="4">

        <div class="mb-3">
            <label class="form-label">Grade Level <span class="text-danger">*</span></label>
            <select class="form-select" name="grade_level" required>
                <option value="">Select...</option>
                <?php foreach (['Kindergarten','1','2','3','4','5','6'] as $gl): ?>
                    <option value="<?= e($gl) ?>" <?= ($enrollData['grade_level'] ?? '') == $gl ? 'selected' : '' ?>>
                        <?= $gl === 'Kindergarten' ? 'Kindergarten' : 'Grade ' . e($gl) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Section</label>
            <select class="form-select" name="section_id">
                <option value="0">To be assigned</option>
                <?php foreach ($sections as $sec): ?>
                    <option value="<?= (int)$sec['id'] ?>" <?= ($enrollData['section_id'] ?? 0) == $sec['id'] ? 'selected' : '' ?>>
                        <?= e($sec['name']) ?> (Grade <?= e((string)$sec['grade_level']) ?>, Capacity: <?= e((string)$sec['capacity']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">School Year</label>
                <input type="text" class="form-control" name="school_year"
                       value="<?= e($enrollData['school_year'] ?? currentSchoolYear()) ?>" readonly>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Term</label>
                <select class="form-select" name="term">
                    <option value="1st Semester" <?= ($enrollData['term'] ?? '1st Semester') === '1st Semester' ? 'selected' : '' ?>>1st Semester</option>
                    <option value="2nd Semester" <?= ($enrollData['term'] ?? '') === '2nd Semester' ? 'selected' : '' ?>>2nd Semester</option>
                </select>
            </div>
        </div>

        <div class="alert alert-info mb-4">
            <i class="bi bi-info-circle me-1"></i>
            After saving, you will be redirected to the payment form. Enrollment review begins after payment submission.
        </div>

        <div class="d-flex justify-content-between">
            <a href="?step=2" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
            <button type="submit" class="btn btn-success">
                Proceed to Payment Form <i class="bi bi-arrow-right ms-1"></i>
            </button>
        </div>
    </form>

<?php endif; ?>

</div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
