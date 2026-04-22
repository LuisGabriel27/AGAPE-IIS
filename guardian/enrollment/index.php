<?php
/**
 * Guardian Enrollment Details
 * Step 1: Student details
 * Step 2: Academic details and submit to payment form
 */

require_once __DIR__ . '/../../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/helpers.php';

$pdo = getDB();
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM guardians WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$guardian = $stmt->fetch();

if (!$guardian) {
    setFlash('danger', 'Guardian profile not found. Please complete your profile first.');
    redirect(APP_URL . '/guardian/complete-profile.php');
}

$step = (int)($_POST['step'] ?? $_GET['step'] ?? 1);
$errors = [];
$sections = $pdo->query("SELECT id, name, grade_level, capacity FROM sections ORDER BY grade_level, name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    if ($step === 2) {
        $_SESSION['enroll'] = [
            'full_name' => trim($_POST['full_name'] ?? ''),
            'birthdate' => trim($_POST['birthdate'] ?? ''),
            'gender' => trim($_POST['gender'] ?? ''),
            'lrn' => trim($_POST['lrn'] ?? ''),
        ];

        if (empty($_SESSION['enroll']['full_name'])) {
            $errors[] = 'Student name is required.';
            $step = 1;
        }
    } elseif ($step === 3) {
        $_SESSION['enroll']['grade_level'] = trim($_POST['grade_level'] ?? '');
        $_SESSION['enroll']['section_id'] = (int)($_POST['section_id'] ?? 0);
        $_SESSION['enroll']['school_year'] = trim($_POST['school_year'] ?? currentSchoolYear());
        $_SESSION['enroll']['term'] = trim($_POST['term'] ?? '1st Semester');

        $enrollData = $_SESSION['enroll'] ?? [];
        if (empty($enrollData['full_name']) || empty($enrollData['grade_level'])) {
            $errors[] = 'Enrollment data is incomplete. Please complete all required fields.';
            $step = 1;
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO students (guardian_id, full_name, birthdate, gender, grade_level, section_id, lrn)
                    VALUES (:gid, :name, :birth, :gender, :grade, :sec, :lrn)
                ");
                $stmt->execute([
                    ':gid' => $guardian['id'],
                    ':name' => $enrollData['full_name'],
                    ':birth' => $enrollData['birthdate'] ?: null,
                    ':gender' => $enrollData['gender'] ?: null,
                    ':grade' => $enrollData['grade_level'],
                    ':sec' => $enrollData['section_id'] ?: null,
                    ':lrn' => $enrollData['lrn'] ?: null,
                ]);
                $studentId = (int)$pdo->lastInsertId();

                $stmt = $pdo->prepare("
                    INSERT INTO enrollments (student_id, school_year, term, status, payment_submitted_at)
                    VALUES (:sid, :sy, :term, 'pending', NULL)
                ");
                $stmt->execute([
                    ':sid' => $studentId,
                    ':sy' => $enrollData['school_year'] ?? currentSchoolYear(),
                    ':term' => $enrollData['term'] ?? '1st Semester',
                ]);
                $enrollmentId = (int)$pdo->lastInsertId();

                $stmt = $pdo->prepare("
                    INSERT INTO payments (enrollment_id, amount, method, description, status)
                    VALUES (:eid, :amt, 'cash', 'Enrollment Fee', 'pending')
                ");
                $stmt->execute([
                    ':eid' => $enrollmentId,
                    ':amt' => 15000.00,
                ]);

                $pdo->commit();
                unset($_SESSION['enroll']);

                auditLog('enrollment_details_submitted', 'enrollments', $enrollmentId, null, [
                    'student_id' => $studentId,
                    'school_year' => $enrollData['school_year'] ?? currentSchoolYear(),
                    'term' => $enrollData['term'] ?? '1st Semester',
                ]);

                setFlash('success', 'Enrollment details saved. Complete the payment form to send your application to admin review.');
                redirect(APP_URL . '/guardian/enrollment/payment.php?enrollment_id=' . $enrollmentId);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Enrollment creation error: ' . $e->getMessage());
                $errors[] = 'An error occurred while saving enrollment details. Please try again.';
                $step = 2;
            }
        }
    }
}

$enrollData = $_SESSION['enroll'] ?? [];
$pageTitle = 'Enrollment';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h4 class="fw-bold mb-0"><i class="bi bi-pencil-square me-2"></i>Student Enrollment</h4>
    </div>
    <div class="col-md-4 text-md-end mt-3 mt-md-0">
        <a class="btn btn-outline-primary" href="<?= APP_URL ?>/guardian/enrollment/certificate.php" target="_blank" rel="noopener">
            <i class="bi bi-printer me-1"></i>Print Certificate
        </a>
    </div>
</div>

<div class="step-indicator mb-4">
    <div class="step <?= e($step >= 1 ? ($step > 1 ? 'completed' : 'active') : '') ?>">
        <span class="step-number"><?= $step > 1 ? '&#10003;' : '1' ?></span>
        <span>Student Info</span>
    </div>
    <div class="step-line"></div>
    <div class="step <?= e($step >= 2 ? ($step > 2 ? 'completed' : 'active') : '') ?>">
        <span class="step-number"><?= $step > 2 ? '&#10003;' : '2' ?></span>
        <span>Enrollment Details</span>
    </div>
    <div class="step-line"></div>
    <div class="step <?= e($step >= 3 ? 'active' : '') ?>">
        <span class="step-number">3</span>
        <span>Payment Form</span>
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
                    <h5 class="fw-bold mb-3">Step 1: Student Information</h5>
                    <form method="POST" action="" id="enrollment-step1">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
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

                        <button type="submit" class="btn btn-primary">Next: Enrollment Details <i class="bi bi-arrow-right ms-1"></i></button>
                    </form>

                <?php else: ?>
                    <h5 class="fw-bold mb-3">Step 2: Enrollment Details</h5>
                    <form method="POST" action="" id="enrollment-step2">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="step" value="3">

                        <div class="mb-3">
                            <label for="grade_level" class="form-label">Grade Level <span class="text-danger">*</span></label>
                            <select class="form-select" id="grade_level" name="grade_level" required>
                                <option value="">Select...</option>
                                <?php for ($g = 7; $g <= 12; $g++): ?>
                                    <option value="<?= e((string)$g) ?>" <?= ($enrollData['grade_level'] ?? '') == $g ? 'selected' : '' ?>>Grade <?= e((string)$g) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="section_id" class="form-label">Section</label>
                            <select class="form-select" id="section_id" name="section_id">
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
                                <label for="school_year" class="form-label">School Year</label>
                                <input type="text" class="form-control" id="school_year" name="school_year" value="<?= e($enrollData['school_year'] ?? currentSchoolYear()) ?>" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="term" class="form-label">Term</label>
                                <select class="form-select" id="term" name="term">
                                    <option value="1st Semester" <?= ($enrollData['term'] ?? '1st Semester') === '1st Semester' ? 'selected' : '' ?>>1st Semester</option>
                                    <option value="2nd Semester" <?= ($enrollData['term'] ?? '') === '2nd Semester' ? 'selected' : '' ?>>2nd Semester</option>
                                </select>
                            </div>
                        </div>

                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            After saving enrollment details, you will be redirected to the payment form. Your enrollment will only be sent to admin review after payment form submission.
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="?step=1" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
                            <button type="submit" class="btn btn-success">Proceed to Payment Form <i class="bi bi-arrow-right ms-1"></i></button>
                        </div>
                    </form>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
