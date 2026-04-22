<?php
/**
 * Guardian Printable Certificate of Enrollment
 * Print-friendly certificate for a selected student and enrollment period.
 */

require_once __DIR__ . '/../../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

$pdo = getDB();
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT id, full_name FROM guardians WHERE user_id = :uid LIMIT 1');
$stmt->execute([':uid' => $userId]);
$guardian = $stmt->fetch();

if (!$guardian) {
    setFlash('danger', 'Guardian profile not found.');
    redirect(APP_URL . '/guardian/dashboard.php');
}

$stmt = $pdo->prepare('SELECT id, full_name FROM students WHERE guardian_id = :gid ORDER BY full_name');
$stmt->execute([':gid' => $guardian['id']]);
$students = $stmt->fetchAll();

if (empty($students)) {
    setFlash('info', 'No student record is linked to this guardian account.');
    redirect(APP_URL . '/guardian/dashboard.php');
}

$allowedStudentIds = array_map(static fn($s) => (int)$s['id'], $students);
$selectedStudent = (int)($_GET['student_id'] ?? $students[0]['id']);
if (!in_array($selectedStudent, $allowedStudentIds, true)) {
    $selectedStudent = (int)$students[0]['id'];
}

$stmt = $pdo->prepare("
    SELECT DISTINCT e.school_year
    FROM enrollments e
    INNER JOIN students s ON s.id = e.student_id
    WHERE s.id = :sid
      AND s.guardian_id = :gid
      AND e.status IN ('approved', 'enrolled')
    ORDER BY e.school_year DESC
");
$stmt->execute([
    ':sid' => $selectedStudent,
    ':gid' => $guardian['id'],
]);
$schoolYears = $stmt->fetchAll(PDO::FETCH_COLUMN);

$selectedYear = trim($_GET['school_year'] ?? ($schoolYears[0] ?? currentSchoolYear()));
if (!empty($schoolYears) && !in_array($selectedYear, $schoolYears, true)) {
    $selectedYear = $schoolYears[0];
}

$selectedTerm = trim($_GET['term'] ?? '1st Semester');
if (!in_array($selectedTerm, ['1st Semester', '2nd Semester'], true)) {
    $selectedTerm = '1st Semester';
}

$stmt = $pdo->prepare("
    SELECT e.id, e.school_year, e.term, e.status, e.enrolled_at,
           s.full_name AS student_name, s.grade_level, s.lrn,
           sec.name AS section_name
    FROM enrollments e
    INNER JOIN students s ON s.id = e.student_id
    LEFT JOIN sections sec ON sec.id = s.section_id
    WHERE s.id = :sid
      AND s.guardian_id = :gid
      AND e.status IN ('approved', 'enrolled')
      AND e.school_year = :sy
      AND e.term = :term
    ORDER BY e.enrolled_at DESC, e.id DESC
    LIMIT 1
");
$stmt->execute([
    ':sid' => $selectedStudent,
    ':gid' => $guardian['id'],
    ':sy' => $selectedYear,
    ':term' => $selectedTerm,
]);
$certificateRecord = $stmt->fetch();

if (!$certificateRecord) {
    $stmt = $pdo->prepare("
        SELECT e.id, e.school_year, e.term, e.status, e.enrolled_at,
               s.full_name AS student_name, s.grade_level, s.lrn,
               sec.name AS section_name
        FROM enrollments e
        INNER JOIN students s ON s.id = e.student_id
        LEFT JOIN sections sec ON sec.id = s.section_id
        WHERE s.id = :sid
          AND s.guardian_id = :gid
          AND e.status IN ('approved', 'enrolled')
        ORDER BY e.school_year DESC, e.term DESC, e.enrolled_at DESC, e.id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':sid' => $selectedStudent,
        ':gid' => $guardian['id'],
    ]);
    $certificateRecord = $stmt->fetch();
    if ($certificateRecord) {
        $selectedYear = $certificateRecord['school_year'];
        $selectedTerm = $certificateRecord['term'];
    }
}

$issuedDate = date('F d, Y');
$pageTitle = 'Certificate';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
    .certificate-page .sheet {
        max-width: 920px;
        margin: 0 auto;
        background: #fff;
        border: 1px solid #dfe6ef;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
        overflow: hidden;
    }
    .certificate-page .sheet-header {
        background: #1e3a8a;
        color: #fff;
        padding: 18px 22px;
    }
    .certificate-page .school-logo {
        width: 56px;
        height: 56px;
        object-fit: cover;
        border-radius: 50%;
        border: 2px solid rgba(255, 255, 255, 0.65);
    }
    .certificate-page .certificate-title {
        letter-spacing: 0.14em;
        text-transform: uppercase;
    }
    .certificate-page .certificate-body {
        font-size: 1.02rem;
        line-height: 1.9;
        text-align: justify;
        color: #0f172a;
    }
    .certificate-page .signature-line {
        border-top: 1px solid #64748b;
        width: 240px;
        margin-top: 52px;
        padding-top: 6px;
        font-size: 0.82rem;
        color: #475569;
        text-align: center;
    }
    @media print {
        body {
            background: #fff !important;
        }
        .no-print,
        .sidebar,
        .top-header,
        .main-footer,
        .sidebar-overlay {
            display: none !important;
        }
        .main-content {
            margin: 0 !important;
            padding: 0 !important;
            min-height: auto !important;
        }
        .certificate-page .sheet {
            margin: 0;
            max-width: none;
            border: none;
            box-shadow: none;
            border-radius: 0;
        }
    }
</style>

<div class="certificate-page">
    <div class="row mb-4 no-print">
        <div class="col-md-8">
            <h4 class="fw-bold mb-0"><i class="bi bi-patch-check-fill me-2"></i>Certificate of Enrollment</h4>
        </div>
        <div class="col-md-4 text-md-end mt-3 mt-md-0">
            <button class="btn btn-outline-primary me-2" onclick="window.print()">Print</button>
            <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/guardian/enrollment/">Back</a>
        </div>
    </div>

    <div class="card mb-4 no-print">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Student</label>
                    <select class="form-select" name="student_id">
                        <?php foreach ($students as $stu): ?>
                            <option value="<?= (int)$stu['id'] ?>" <?= $selectedStudent === (int)$stu['id'] ? 'selected' : '' ?>>
                                <?= e($stu['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">School Year</label>
                    <select class="form-select" name="school_year">
                        <?php if (empty($schoolYears)): ?>
                            <option value="<?= e(currentSchoolYear()) ?>"><?= e(currentSchoolYear()) ?></option>
                        <?php else: ?>
                            <?php foreach ($schoolYears as $year): ?>
                                <option value="<?= e($year) ?>" <?= $selectedYear === $year ? 'selected' : '' ?>><?= e($year) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Term</label>
                    <select class="form-select" name="term">
                        <option value="1st Semester" <?= $selectedTerm === '1st Semester' ? 'selected' : '' ?>>1st Semester</option>
                        <option value="2nd Semester" <?= $selectedTerm === '2nd Semester' ? 'selected' : '' ?>>2nd Semester</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="sheet">
        <div class="sheet-header">
            <div class="d-flex align-items-center gap-3">
                <img src="<?= APP_URL ?>/assets/images/branding/agape-logo.jpg" alt="School Logo" class="school-logo">
                <div>
                    <div class="fw-bold fs-5"><?= e(APP_NAME) ?></div>
                    <div class="small">Office of the Registrar</div>
                </div>
                <div class="ms-auto text-end small">
                    <div>Date Issued: <?= e($issuedDate) ?></div>
                </div>
            </div>
        </div>

        <div class="p-5">
            <h4 class="text-center mb-4 certificate-title">Certificate of Enrollment</h4>

            <?php if (!$certificateRecord): ?>
                <div class="alert alert-warning mb-0">
                    No approved or enrolled record was found for the selected student and term.
                </div>
            <?php else: ?>
                <div class="certificate-body">
                    This is to certify that <strong><?= e($certificateRecord['student_name']) ?></strong>
                    with Learner Reference Number (LRN)
                    <strong><?= e($certificateRecord['lrn'] ?: 'N/A') ?></strong>
                    is officially <strong><?= e($certificateRecord['status']) ?></strong>
                    at <strong><?= e(APP_NAME) ?></strong> for
                    <strong><?= e($certificateRecord['term']) ?></strong>,
                    School Year <strong><?= e($certificateRecord['school_year']) ?></strong>,
                    under <strong>Grade <?= e($certificateRecord['grade_level'] ?: 'N/A') ?></strong>
                    and <strong>Section <?= e($certificateRecord['section_name'] ?: 'N/A') ?></strong>.
                    <br><br>
                    This certification is issued upon the request of the parent or guardian
                    for whatever legal purpose it may serve.
                </div>

                <div class="row mt-5">
                    <div class="col-md-6">
                        <div class="small text-muted">Parent/Guardian</div>
                        <div class="fw-semibold"><?= e($guardian['full_name']) ?></div>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <div class="small text-muted">Enrollment Status</div>
                        <span class="badge text-bg-primary"><?= e(ucfirst($certificateRecord['status'])) ?></span>
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-4">
                    <div class="signature-line">Registrar / Authorized Signatory</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
