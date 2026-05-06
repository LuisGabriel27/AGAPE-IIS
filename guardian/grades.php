<?php
/**
 * Guardian Grades Page
 * View student grades filtered by school year and term.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo    = getDB();
$userId = $_SESSION['user_id'];

// Get guardian and students
$stmt = $pdo->prepare("SELECT id FROM guardians WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$guardian = $stmt->fetch();

$students = [];
if ($guardian) {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE guardian_id = :gid ORDER BY last_name, first_name");
    $stmt->execute([':gid' => $guardian['id']]);
    $students = $stmt->fetchAll();
}

// Filters
$selectedStudent = (int)($_GET['student_id'] ?? ($students[0]['id'] ?? 0));
$selectedYear    = $_GET['school_year'] ?? currentSchoolYear();
$selectedTerm    = $_GET['term'] ?? '1st Semester';

// Fetch grades
$grades = [];
$gwa    = 0;
if ($selectedStudent) {
    $stmt = $pdo->prepare("
        SELECT g.*, sub.code, sub.name AS subject_name, sub.units
        FROM grades g
        JOIN subjects sub ON g.subject_id = sub.id
        WHERE g.student_id = :sid AND g.school_year = :sy AND g.term = :term AND g.published = 1
        ORDER BY sub.name
    ");
    $stmt->execute([':sid' => $selectedStudent, ':sy' => $selectedYear, ':term' => $selectedTerm]);
    $grades = $stmt->fetchAll();

    // Calculate GWA
    $totalUnits = 0;
    $totalWeighted = 0;
    foreach ($grades as $g) {
        if ($g['final_grade'] !== null) {
            $totalWeighted += $g['final_grade'] * $g['units'];
            $totalUnits += $g['units'];
        }
    }
    $gwa = $totalUnits > 0 ? round($totalWeighted / $totalUnits, 2) : 0;
}

// Get available school years
$years = $pdo->query("SELECT DISTINCT school_year FROM grades ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($years)) $years = [currentSchoolYear()];

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
               href="<?= e(APP_URL . '/guardian/report-card.php?' . http_build_query(['student_id' => $selectedStudent, 'school_year' => $selectedYear, 'term' => $selectedTerm])) ?>"
               target="_blank" rel="noopener">
                <i class="bi bi-printer me-1"></i>Print Report Card
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end" id="grades-filter">
            <div class="col-md-4">
                <label for="student_id" class="form-label">Student</label>
                <select class="form-select" name="student_id" id="student_id">
                    <?php foreach ($students as $stu): ?>
                        <option value="<?= (int)$stu['id'] ?>" <?= $selectedStudent == $stu['id'] ? 'selected' : '' ?>><?= e(format_name($stu['first_name'], $stu['last_name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="school_year" class="form-label">School Year</label>
                <select class="form-select" name="school_year" id="school_year">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= e($y) ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= e($y) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="term" class="form-label">Term</label>
                <select class="form-select" name="term" id="term">
                    <option value="1st Semester" <?= $selectedTerm === '1st Semester' ? 'selected' : '' ?>>1st Semester</option>
                    <option value="2nd Semester" <?= $selectedTerm === '2nd Semester' ? 'selected' : '' ?>>2nd Semester</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-filter me-1"></i>Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- Grades Table -->
<div class="table-container p-0">
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="grades-table">
            <thead>
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
                    <tr><td colspan="7" class="text-center text-muted py-4">No grades available for the selected filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($grades as $g): ?>
                    <tr>
                        <td><?= e($g['code']) ?></td>
                        <td><?= e($g['subject_name']) ?></td>
                        <td class="text-center"><?= e((string)$g['units']) ?></td>
                        <td class="text-center"><?= e($g['midterm'] !== null ? number_format((float)$g['midterm'], 2) : '—') ?></td>
                        <td class="text-center"><?= e($g['finals'] !== null ? number_format((float)$g['finals'], 2) : '—') ?></td>
                        <td class="text-center fw-bold"><?= e($g['final_grade'] !== null ? number_format((float)$g['final_grade'], 2) : '—') ?></td>
                        <td class="text-center">
                            <?php if ($g['final_grade'] !== null): ?>
                                <?php if ($g['final_grade'] >= 75): ?>
                                    <span class="badge bg-success">Passed</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Failed</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-secondary">Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($grades)): ?>
            <tfoot>
                <tr class="table-primary fw-bold">
                    <td colspan="5" class="text-end">General Weighted Average (GWA):</td>
                    <td class="text-center"><?= e($gwa > 0 ? number_format((float)$gwa, 2) : 'N/A') ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
