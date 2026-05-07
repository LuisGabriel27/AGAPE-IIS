<?php
/**
 * Guardian Grades Page
 * View student grades filtered by school year using DepEd K-12 grading periods.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo    = getDB();
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT id FROM guardians WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$guardian = $stmt->fetch();

$students = [];
if ($guardian) {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE guardian_id = :gid ORDER BY last_name, first_name");
    $stmt->execute([':gid' => $guardian['id']]);
    $students = $stmt->fetchAll();
}

$selectedStudent = (int)($_GET['student_id'] ?? ($students[0]['id'] ?? 0));
$selectedYear    = $_GET['school_year'] ?? currentSchoolYear();

$grades = [];
$generalAverage = null;
if ($selectedStudent) {
    $stmt = $pdo->prepare("
        SELECT g.*, sub.code, sub.name AS subject_name
        FROM grades g
        JOIN subjects sub ON g.subject_id = sub.id
        WHERE g.student_id = :sid
          AND g.school_year = :sy
          AND g.published = 1
        ORDER BY sub.name
    ");
    $stmt->execute([':sid' => $selectedStudent, ':sy' => $selectedYear]);
    $grades = $stmt->fetchAll();

    $finalRatings = [];
    foreach ($grades as $g) {
        if ($g['final_grade'] !== null) {
            $finalRatings[] = (float)$g['final_grade'];
        }
    }
    $generalAverage = !empty($finalRatings) ? round(array_sum($finalRatings) / count($finalRatings), 2) : null;
}

$years = $pdo->query("SELECT DISTINCT school_year FROM grades ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($years)) {
    $years = [currentSchoolYear()];
}

$pageTitle = 'Grades';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-8">
        <div class="page-header-guardian">
            <h4><i class="bi bi-card-checklist me-2"></i>Student Grades</h4>
        </div>
    </div>
    <div class="col-md-4 text-md-end mt-3 mt-md-0">
        <?php if ($selectedStudent > 0): ?>
            <a class="btn btn-outline-primary"
               href="<?= e(APP_URL . '/guardian/report-card.php?' . http_build_query(['student_id' => $selectedStudent, 'school_year' => $selectedYear])) ?>"
               target="_blank" rel="noopener">
                <i class="bi bi-printer me-1"></i>Print Report Card
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end" id="grades-filter">
            <div class="col-md-5">
                <label for="student_id" class="form-label">Student</label>
                <select class="form-select" name="student_id" id="student_id">
                    <?php foreach ($students as $stu): ?>
                        <option value="<?= (int)$stu['id'] ?>" <?= $selectedStudent == $stu['id'] ? 'selected' : '' ?>><?= e(format_name($stu['first_name'], $stu['last_name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label for="school_year" class="form-label">School Year</label>
                <select class="form-select" name="school_year" id="school_year">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= e($y) ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= e($y) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-filter me-1"></i>Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="table-container p-0 mb-4">
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="grades-table">
            <thead>
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
                    <tr><td colspan="9" class="text-center text-muted py-4">No grades available for the selected filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($grades as $g): ?>
                    <?php $final = $g['final_grade'] !== null ? (float)$g['final_grade'] : null; ?>
                    <tr>
                        <td><?= e($g['code']) ?></td>
                        <td><?= e($g['subject_name']) ?></td>
                        <td class="text-center"><?= e($g['quarter1'] !== null ? number_format((float)$g['quarter1'], 2) : '-') ?></td>
                        <td class="text-center"><?= e($g['quarter2'] !== null ? number_format((float)$g['quarter2'], 2) : '-') ?></td>
                        <td class="text-center"><?= e($g['quarter3'] !== null ? number_format((float)$g['quarter3'], 2) : '-') ?></td>
                        <td class="text-center"><?= e($g['quarter4'] !== null ? number_format((float)$g['quarter4'], 2) : '-') ?></td>
                        <td class="text-center fw-bold"><?= e($final !== null ? number_format($final, 2) : 'Pending') ?></td>
                        <td class="text-center"><?= e(depedDescriptor($final)) ?></td>
                        <td class="text-center"><span class="badge <?= e(depedRemarkBadgeClass($final)) ?>"><?= e(depedRemark($final)) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($grades)): ?>
            <tfoot>
                <tr class="table-primary fw-bold">
                    <td colspan="6" class="text-end">General Average:</td>
                    <td class="text-center"><?= e($generalAverage !== null ? number_format($generalAverage, 2) : 'N/A') ?></td>
                    <td class="text-center"><?= e($generalAverage !== null ? depedDescriptor($generalAverage) : 'Pending') ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold">Descriptors, Grading Scale, and Remarks</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Descriptor</th><th class="text-center">Grading Scale</th><th class="text-center">Remarks</th></tr></thead>
                <tbody>
                    <tr><td>Outstanding</td><td class="text-center">90-100</td><td class="text-center">Passed</td></tr>
                    <tr><td>Very Satisfactory</td><td class="text-center">85-89</td><td class="text-center">Passed</td></tr>
                    <tr><td>Satisfactory</td><td class="text-center">80-84</td><td class="text-center">Passed</td></tr>
                    <tr><td>Fairly Satisfactory</td><td class="text-center">75-79</td><td class="text-center">Passed</td></tr>
                    <tr><td>Did Not Meet Expectations</td><td class="text-center">Below 75</td><td class="text-center">Failed</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
