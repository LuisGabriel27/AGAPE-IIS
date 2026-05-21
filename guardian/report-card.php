<?php
/**
 * Guardian Printable Report Card
 * Print-friendly DepEd-style grade report for a selected student and school year.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/official-document.php';

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
$selectedStudent = (int)($_GET['student_id'] ?? $students[0]['id']);
if (!in_array($selectedStudent, $allowedStudentIds, true)) {
    $selectedStudent = (int)$students[0]['id'];
}

$selectedYear = trim($_GET['school_year'] ?? currentSchoolYear());
$selectedTerm = normalizeAcademicTerm($_GET['term'] ?? currentAcademicTerm());

$stmt = $pdo->prepare("
    SELECT s.id, s.first_name, s.last_name, s.grade_level, s.lrn,
           sec.name AS section_name,
           CASE WHEN t.first_name = '' THEN t.last_name ELSE t.last_name || ', ' || t.first_name END AS adviser_name
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
$terms = array_keys(academicTermOptions());

$gradeParams = [
    ':sid' => $selectedStudent,
    ':sy' => $selectedYear,
];
$gradeTermClause = academicTermWhereClause('g.term', 'report_grade_term', $gradeParams, $selectedTerm, true);
$stmt = $pdo->prepare("
    SELECT sub.id AS subject_id, sub.code, sub.name AS subject_name,
           MAX(g.quarter1) AS quarter1,
           MAX(g.quarter2) AS quarter2,
           MAX(g.quarter3) AS quarter3,
           MAX(g.quarter4) AS quarter4
    FROM grades g
    INNER JOIN subjects sub ON sub.id = g.subject_id
    WHERE g.student_id = :sid
      AND g.school_year = :sy
      AND {$gradeTermClause}
      AND g.published = 1
    GROUP BY sub.id, sub.code, sub.name
    ORDER BY sub.name
");
$stmt->execute($gradeParams);
$grades = $stmt->fetchAll();

$finalRatings = [];
$failingSubjects = 0;
foreach ($grades as $idx => $grade) {
    $finalGrade = finalRatingFromQuarterGrades($grade);
    $grades[$idx]['final_grade'] = $finalGrade;
    if ($finalGrade !== null) {
        $finalRatings[] = $finalGrade;
        if ($finalGrade < 75) {
            $failingSubjects++;
        }
    }
}

$generalAverage = !empty($finalRatings) ? round(array_sum($finalRatings) / count($finalRatings), 2) : null;
$generalRemark = $generalAverage === null ? 'Pending' : ($failingSubjects > 0 ? 'Failed' : depedRemark($generalAverage));

$reportDate = date('F d, Y');
$principalName = 'MRS. TERESA C. ATIENZA MACED, GC';
$pageTitle = 'Report Card';
require_once __DIR__ . '/../includes/header.php';
renderOfficialDocumentStyles();
?>
<style>
    .report-card-page .meta-table th {
        width: 1.55in;
        background: #f7f7f7;
        font-size: 9.5pt;
        white-space: nowrap;
    }

    .report-card-page .meta-table td,
    .report-card-page .grades-table td,
    .report-card-page .grades-table th,
    .report-card-page .scale-table td,
    .report-card-page .scale-table th {
        font-size: 9.2pt;
        padding: 0.07in;
    }

    .report-card-page .report-card-meta {
        margin-bottom: 0.18in;
        text-align: center;
        font-family: Arial, sans-serif;
        font-size: 8.5pt;
        line-height: 1.45;
        text-transform: uppercase;
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
                <div class="col-md-5">
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
                        <?php foreach ($years as $year): ?>
                            <option value="<?= e($year) ?>" <?= $selectedYear === $year ? 'selected' : '' ?>><?= e($year) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Quarter</label>
                    <select class="form-select" name="term">
                        <?php foreach ($terms as $term): ?>
                            <option value="<?= e($term) ?>" <?= $selectedTerm === $term ? 'selected' : '' ?>><?= e($term) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="official-document-sheet official-report-card-sheet">
        <?php renderOfficialDocumentHeader(); ?>

        <div class="official-document-body">
            <h2 class="official-document-title">Student Report Card</h2>
            <div class="report-card-meta">
                <div>School Year: <strong><?= e($selectedYear) ?></strong> / <strong><?= e($selectedTerm) ?></strong></div>
                <div>Date Issued: <strong><?= e($reportDate) ?></strong></div>
            </div>

            <table class="table table-bordered meta-table align-middle mb-4">
                <tbody>
                    <tr>
                        <th>Student Name</th>
                        <td><?= e(format_name($student['first_name'], $student['last_name'])) ?></td>
                        <th>LRN</th>
                        <td><?= e($student['lrn'] ?: 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Grade Level</th>
                        <td><?= e(formatGradeLevel((string)($student['grade_level'] ?: ''))) ?></td>
                        <th>Section</th>
                        <td><?= e($student['section_name'] ?: 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Class Adviser</th>
                        <td><?= e($student['adviser_name'] ?: 'N/A') ?></td>
                        <th>Guardian</th>
                        <td><?= e(format_name($guardian['first_name'], $guardian['last_name'])) ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0 grades-table">
                    <thead class="table-light">
                        <tr>
                            <th>Subject Code</th>
                            <th>Subject</th>
                            <th class="text-center">1st</th>
                            <th class="text-center">2nd</th>
                            <th class="text-center">3rd</th>
                            <th class="text-center">4th</th>
                            <th class="text-center">Final Rating</th>
                            <th class="text-center">Descriptor</th>
                            <th class="text-center">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($grades)): ?>
                            <?= emptyStateRow(9, 'No grades to show for this school year and quarter.', 'Grades appear on the report card once the class teacher encodes and publishes them.', 'bi-card-checklist') ?>
                        <?php else: ?>
                            <?php foreach ($grades as $grade): ?>
                                <?php $final = $grade['final_grade'] !== null ? (float)$grade['final_grade'] : null; ?>
                                <tr>
                                    <td><?= e($grade['code']) ?></td>
                                    <td><?= e($grade['subject_name']) ?></td>
                                    <td class="text-center"><?= e($grade['quarter1'] !== null ? number_format((float)$grade['quarter1'], 2) : '-') ?></td>
                                    <td class="text-center"><?= e($grade['quarter2'] !== null ? number_format((float)$grade['quarter2'], 2) : '-') ?></td>
                                    <td class="text-center"><?= e($grade['quarter3'] !== null ? number_format((float)$grade['quarter3'], 2) : '-') ?></td>
                                    <td class="text-center"><?= e($grade['quarter4'] !== null ? number_format((float)$grade['quarter4'], 2) : '-') ?></td>
                                    <td class="text-center fw-semibold"><?= e($final !== null ? number_format($final, 2) : 'Pending') ?></td>
                                    <td class="text-center"><?= e(depedDescriptor($final)) ?></td>
                                    <td class="text-center"><?= e(depedRemark($final)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td colspan="6" class="text-end">General Average</td>
                            <td class="text-center"><?= e($generalAverage !== null ? number_format($generalAverage, 2) : 'N/A') ?></td>
                            <td class="text-center"><?= e($generalAverage !== null ? depedDescriptor($generalAverage) : 'Pending') ?></td>
                            <td class="text-center"><?= e($generalRemark) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="table-responsive mt-4">
                <table class="table table-bordered scale-table mb-0">
                    <thead class="table-light"><tr><th>Descriptor</th><th class="text-center">Grading Scale</th><th class="text-center">Remarks</th></tr></thead>
                    <tbody>
                        <tr><td>Outstanding</td><td class="text-center">90-100</td><td class="text-center">Passed</td></tr>
                        <tr><td>Very Satisfactory</td><td class="text-center">85-89</td><td class="text-center">Passed</td></tr>
                        <tr><td>Satisfactory</td><td class="text-center">80-84</td><td class="text-center">Passed</td></tr>
                        <tr><td>Fairly Satisfactory</td><td class="text-center">75-79</td><td class="text-center">Passed</td></tr>
                        <tr><td>Did Not Meet Expectations</td><td class="text-center">Below 75</td><td class="text-center">Failed</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="official-signatures">
                <div class="official-signature">
                    <div class="official-signature-caption">Certified Correct:</div>
                    <div class="official-signature-name"><?= e($student['adviser_name'] ?: 'Class Adviser') ?></div>
                    <div class="official-signature-role">Class Adviser</div>
                </div>
                <div class="official-signature">
                    <div class="official-signature-caption">Attested by:</div>
                    <div class="official-signature-name"><?= e($principalName) ?></div>
                    <div class="official-signature-role">School Principal</div>
                </div>
            </div>
        </div>

        <?php renderOfficialDocumentFooter(); ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
