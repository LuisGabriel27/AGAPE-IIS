<?php
/**
 * Guardian Printable Report Card
 * Print-friendly grade report for a selected student, school year, and term.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

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

$selectedYear = trim($_GET['school_year'] ?? currentSchoolYear());
$selectedTerm = trim($_GET['term'] ?? '1st Semester');
if (!in_array($selectedTerm, ['1st Semester', '2nd Semester'], true)) {
    $selectedTerm = '1st Semester';
}

$stmt = $pdo->prepare("
    SELECT s.id, s.full_name, s.grade_level, s.lrn,
           sec.name AS section_name,
           t.full_name AS adviser_name
    FROM students s
    LEFT JOIN sections sec ON sec.id = s.section_id
    LEFT JOIN teachers t ON t.id = sec.adviser_id
    WHERE s.id = :sid AND s.guardian_id = :gid
    LIMIT 1
");
$stmt->execute([':sid' => $selectedStudent, ':gid' => $guardian['id']]);
$student = $stmt->fetch();

if (!$student) {
    setFlash('danger', 'Student not found for this guardian.');
    redirect(APP_URL . '/guardian/grades.php');
}

$stmt = $pdo->prepare('SELECT DISTINCT school_year FROM grades WHERE student_id = :sid ORDER BY school_year DESC');
$stmt->execute([':sid' => $selectedStudent]);
$years = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($years)) {
    $years = [currentSchoolYear()];
}
if (!in_array($selectedYear, $years, true)) {
    $selectedYear = $years[0];
}

$stmt = $pdo->prepare("
    SELECT sub.code, sub.name AS subject_name, sub.units,
           g.midterm, g.finals, g.final_grade
    FROM grades g
    INNER JOIN subjects sub ON sub.id = g.subject_id
    WHERE g.student_id = :sid
      AND g.school_year = :sy
      AND g.term = :term
    ORDER BY sub.name
");
$stmt->execute([
    ':sid' => $selectedStudent,
    ':sy' => $selectedYear,
    ':term' => $selectedTerm,
]);
$grades = $stmt->fetchAll();

$totalUnits = 0;
$weightedTotal = 0.0;
$failingSubjects = 0;
foreach ($grades as $grade) {
    if ($grade['final_grade'] !== null) {
        $units = (int)$grade['units'];
        $finalGrade = (float)$grade['final_grade'];
        $totalUnits += $units;
        $weightedTotal += $finalGrade * $units;
        if ($finalGrade < 75) {
            $failingSubjects++;
        }
    }
}

$gwa = $totalUnits > 0 ? round($weightedTotal / $totalUnits, 2) : null;
$generalRemark = 'Pending';
if ($gwa !== null) {
    $generalRemark = $failingSubjects > 0 ? 'Needs Improvement' : 'Passed';
}

$reportDate = date('F d, Y');
$pageTitle = 'Report Card';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
    .report-card-page .sheet {
        max-width: 980px;
        margin: 0 auto;
        background: #fff;
        border: 1px solid #dfe6ef;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
        overflow: hidden;
    }
    .report-card-page .sheet-header {
        background: #1d4ed8;
        color: #fff;
        padding: 18px 22px;
    }
    .report-card-page .school-logo {
        width: 56px;
        height: 56px;
        object-fit: cover;
        border-radius: 50%;
        border: 2px solid rgba(255, 255, 255, 0.65);
    }
    .report-card-page .meta-table th {
        width: 190px;
        background: #f8fafc;
    }
    .report-card-page .signature-line {
        border-top: 1px solid #64748b;
        width: 220px;
        margin-top: 36px;
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
        .report-card-page .sheet {
            margin: 0;
            max-width: none;
            border: none;
            box-shadow: none;
            border-radius: 0;
        }
    }
</style>

<div class="report-card-page">
    <div class="row mb-4 no-print">
        <div class="col-md-8">
            <h4 class="fw-bold mb-0"><i class="bi bi-printer-fill me-2"></i>Student Report Card</h4>
        </div>
        <div class="col-md-4 text-md-end mt-3 mt-md-0">
            <button class="btn btn-outline-primary me-2" onclick="window.print()">Print</button>
            <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/guardian/grades.php">Back</a>
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
                        <?php foreach ($years as $year): ?>
                            <option value="<?= e($year) ?>" <?= $selectedYear === $year ? 'selected' : '' ?>><?= e($year) ?></option>
                        <?php endforeach; ?>
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
                    <div class="small">Student Report Card</div>
                </div>
                <div class="ms-auto text-end small">
                    <div>Issued: <?= e($reportDate) ?></div>
                    <div>School Year: <?= e($selectedYear) ?></div>
                    <div>Term: <?= e($selectedTerm) ?></div>
                </div>
            </div>
        </div>

        <div class="p-4">
            <table class="table table-bordered meta-table align-middle mb-4">
                <tbody>
                    <tr>
                        <th>Student Name</th>
                        <td><?= e($student['full_name']) ?></td>
                        <th>LRN</th>
                        <td><?= e($student['lrn'] ?: 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Grade Level</th>
                        <td>Grade <?= e($student['grade_level'] ?: 'N/A') ?></td>
                        <th>Section</th>
                        <td><?= e($student['section_name'] ?: 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Class Adviser</th>
                        <td><?= e($student['adviser_name'] ?: 'N/A') ?></td>
                        <th>Guardian</th>
                        <td><?= e($guardian['full_name']) ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Subject Code</th>
                            <th>Subject</th>
                            <th class="text-center">Units</th>
                            <th class="text-center">Midterm</th>
                            <th class="text-center">Finals</th>
                            <th class="text-center">Final Grade</th>
                            <th class="text-center">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($grades)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    No grades available for this period.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($grades as $grade): ?>
                                <?php
                                $final = $grade['final_grade'] !== null ? (float)$grade['final_grade'] : null;
                                $remark = $final === null ? 'Pending' : ($final >= 75 ? 'Passed' : 'Failed');
                                ?>
                                <tr>
                                    <td><?= e($grade['code']) ?></td>
                                    <td><?= e($grade['subject_name']) ?></td>
                                    <td class="text-center"><?= e((string)(int)$grade['units']) ?></td>
                                    <td class="text-center"><?= e($grade['midterm'] !== null ? number_format((float)$grade['midterm'], 2) : '-') ?></td>
                                    <td class="text-center"><?= e($grade['finals'] !== null ? number_format((float)$grade['finals'], 2) : '-') ?></td>
                                    <td class="text-center fw-semibold"><?= e($final !== null ? number_format($final, 2) : '-') ?></td>
                                    <td class="text-center"><?= e($remark) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td colspan="5" class="text-end">General Weighted Average</td>
                            <td class="text-center"><?= e($gwa !== null ? number_format((float)$gwa, 2) : 'N/A') ?></td>
                            <td class="text-center"><?= e($generalRemark) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="d-flex justify-content-between mt-4">
                <div class="signature-line">Class Adviser</div>
                <div class="signature-line">School Principal</div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
