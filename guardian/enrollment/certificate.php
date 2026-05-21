<?php
/**
 * Guardian Printable Certificate of Enrollment
 * Print-friendly certificate for a selected student and enrollment period.
 */

require_once __DIR__ . '/../../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/official-document.php';

$pdo = getDB();
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT id, first_name, last_name FROM guardians WHERE user_id = :uid LIMIT 1');
$stmt->execute([':uid' => $userId]);
$guardian = $stmt->fetch();

if (!$guardian) {
    setFlash('danger', 'Guardian profile not found.');
    redirect(APP_URL . '/guardian/dashboard.php');
}

$stmt = $pdo->prepare('SELECT id, first_name, last_name FROM students WHERE guardian_id = :gid ORDER BY last_name, first_name');
$stmt->execute([':gid' => $guardian['id']]);
$students = $stmt->fetchAll();

if (empty($students)) {
    setFlash('info', 'No student record is linked to this guardian account.');
    redirect(APP_URL . '/guardian/dashboard.php');
}

$allowedStudentIds = array_map(static fn($s) => (int)$s['id'], $students);
$requestedStudent = (int)($_GET['student_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT s.id
    FROM students s
    INNER JOIN enrollments e ON e.student_id = s.id
    WHERE s.guardian_id = :gid
      AND e.status = 'enrolled'
    ORDER BY e.school_year DESC,
             CASE e.term WHEN '2nd Semester' THEN 2 WHEN '1st Semester' THEN 1 ELSE 0 END DESC,
             e.enrolled_at DESC NULLS LAST,
             e.id DESC
    LIMIT 1
");
$stmt->execute([':gid' => $guardian['id']]);
$defaultCertificateStudent = (int)($stmt->fetchColumn() ?: 0);

$selectedStudent = $requestedStudent > 0
    ? $requestedStudent
    : ($defaultCertificateStudent > 0 ? $defaultCertificateStudent : (int)$students[0]['id']);

if (!in_array($selectedStudent, $allowedStudentIds, true)) {
    $selectedStudent = $defaultCertificateStudent > 0 && in_array($defaultCertificateStudent, $allowedStudentIds, true)
        ? $defaultCertificateStudent
        : (int)$students[0]['id'];
}

$stmt = $pdo->prepare("
    SELECT DISTINCT e.school_year
    FROM enrollments e
    INNER JOIN students s ON s.id = e.student_id
    WHERE s.id = :sid
      AND s.guardian_id = :gid
      AND e.status = 'enrolled'
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

$selectedTerm = normalizeAcademicTerm($_GET['term'] ?? currentAcademicTerm());

$stmt = $pdo->prepare("
    SELECT e.id, e.school_year, e.term, e.status, e.enrolled_at,
           CASE WHEN s.first_name = '' THEN s.last_name ELSE s.last_name || ', ' || s.first_name END AS student_name,
           s.grade_level, s.lrn, sec.name AS section_name,
           NULLIF(TRIM(BOTH ' ,' FROM COALESCE(t.last_name, '') || ', ' || COALESCE(t.first_name, '')), '') AS adviser_name
    FROM enrollments e
    INNER JOIN students s ON s.id = e.student_id
    LEFT JOIN sections sec ON sec.id = s.section_id
    LEFT JOIN teachers t ON t.id = sec.adviser_id
    WHERE s.id = :sid
      AND s.guardian_id = :gid
      AND e.status = 'enrolled'
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
               CASE WHEN s.first_name = '' THEN s.last_name ELSE s.last_name || ', ' || s.first_name END AS student_name,
               s.grade_level, s.lrn, sec.name AS section_name,
               NULLIF(TRIM(BOTH ' ,' FROM COALESCE(t.last_name, '') || ', ' || COALESCE(t.first_name, '')), '') AS adviser_name
        FROM enrollments e
        INNER JOIN students s ON s.id = e.student_id
        LEFT JOIN sections sec ON sec.id = s.section_id
        LEFT JOIN teachers t ON t.id = sec.adviser_id
        WHERE s.id = :sid
          AND s.guardian_id = :gid
          AND e.status = 'enrolled'
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
$issuedDay = date('j');
$issuedDaySuffix = date('S');
$issuedMonthYear = date('F, Y');
$principalName = 'MRS. TERESA C. ATIENZA MACED, GC';
$pageTitle = 'Certificate';
require_once __DIR__ . '/../../includes/header.php';
renderOfficialDocumentStyles();
?>
<style>
    .certificate-page .certificate-date-line {
        margin: -0.12in 0 0.16in;
        text-align: right;
        font-size: 12pt;
    }

    .certificate-page .certificate-date-value {
        display: inline-block;
        min-width: 1.9in;
        border-bottom: 1px solid #111827;
        text-align: center;
        line-height: 1.2;
    }

    .certificate-page .certificate-verified-label {
        margin-top: 0.55in;
        margin-bottom: 0.4in;
        font-weight: 700;
    }

    .certificate-page .certificate-signatures {
        margin-top: 0;
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
                                <?= e(format_name($stu['first_name'], $stu['last_name'])) ?>
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

    <div class="official-document-sheet official-certificate-sheet">
        <?php renderOfficialDocumentHeader(); ?>

        <div class="official-document-body">
            <h2 class="official-document-title">Certificate of Enrollment</h2>

            <?php if (!$certificateRecord): ?>
                <div class="alert alert-warning mb-0">
                    No approved or enrolled record was found for the selected student and term.
                    Certificates are generated only after the Registrar submits the enrollment to teachers.
                </div>
            <?php else: ?>
                <div class="certificate-date-line">
                    Date: <span class="certificate-date-value"><?= e($issuedDate) ?></span>
                </div>

                <p class="official-salutation">To Whom It May Concern:</p>

                <p class="official-paragraph">
                    This is to certify that <span class="official-fill"><?= e($certificateRecord['student_name']) ?></span>
                    is officially enrolled as a <span class="official-fill"><?= e(formatGradeLevel((string)($certificateRecord['grade_level'] ?: ''))) ?></span>
                    pupil with Learner Reference Number
                    <span class="official-fill"><?= e($certificateRecord['lrn'] ?: 'N/A') ?></span>
                    in this institution this School Year
                    <span class="official-fill"><?= e($certificateRecord['school_year']) ?></span>.
                </p>

                <p class="official-paragraph">
                    This certification is issued upon the request of the above mentioned for whatever legal purpose it may serve him/her best.
                </p>

                <p class="official-paragraph">
                    Given this <span class="official-fill"><?= e($issuedDay) ?><sup><?= e($issuedDaySuffix) ?></sup></span>
                    day of <span class="official-fill"><?= e($issuedMonthYear) ?></span> at Agape Boracay Academy Inc.,
                    Sitio Cagban, Barangay Manocmanoc, Boracay Island, Malay, Aklan.
                </p>

                <div class="certificate-verified-label">Verified by:</div>

                <div class="official-signatures certificate-signatures">
                    <div class="official-signature">
                        <div class="official-signature-name"><?= e($certificateRecord['adviser_name'] ?: 'Teacher-Adviser') ?></div>
                        <div class="official-signature-role">Teacher-Adviser</div>
                    </div>
                    <div class="official-signature">
                        <div class="official-signature-name"><?= e($principalName) ?></div>
                        <div class="official-signature-role">School Head</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php renderOfficialDocumentFooter(); ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
