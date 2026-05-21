<?php
/**
 * Teacher Students
 * Shows students officially submitted/enrolled into the teacher's advisory sections.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('teacher');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$userId = (int)($_SESSION['user_id'] ?? 0);
$activeYear = currentSchoolYear();
$activeTerm = currentAcademicTerm();

$stmt = $pdo->prepare("SELECT * FROM teachers WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $userId]);
$teacher = $stmt->fetch();

if (!$teacher) {
    setFlash('danger', 'Teacher profile not found.');
    redirect(APP_URL . '/teacher/teacher-dashboard.php');
}

$requestedSchoolYear = trim((string)($_GET['school_year'] ?? ''));
$requestedTerm = normalizeAcademicTerm($_GET['term'] ?? $activeTerm);
$search = trim((string)($_GET['search'] ?? ''));

$yearsStmt = $pdo->prepare("
    SELECT DISTINCT e.school_year
    FROM enrollments e
    INNER JOIN students s ON s.id = e.student_id
    INNER JOIN sections sec ON sec.id = s.section_id
    WHERE sec.adviser_id = :tid
      AND e.status = 'enrolled'
    ORDER BY e.school_year DESC
");
$yearsStmt->execute([':tid' => (int)$teacher['id']]);
$schoolYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);
$schoolYear = $requestedSchoolYear !== '' ? $requestedSchoolYear : (in_array($activeYear, $schoolYears, true) ? $activeYear : (string)($schoolYears[0] ?? $activeYear));
$schoolTerm = $requestedTerm;

$where = [
    'sec.adviser_id = :teacher_id',
    "e.status = 'enrolled'",
    'e.term = :term',
];
$params = [
    ':teacher_id' => (int)$teacher['id'],
    ':term' => $schoolTerm,
];
if ($schoolYear !== '') {
    $where[] = 'e.school_year = :school_year';
    $params[':school_year'] = $schoolYear;
}
if ($search !== '') {
    $where[] = "(s.last_name ILIKE :search_last OR s.first_name ILIKE :search_first OR s.lrn ILIKE :search_lrn)";
    $params[':search_last'] = $search . '%';
    $params[':search_first'] = '%' . $search . '%';
    $params[':search_lrn'] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT s.id AS student_id,
           s.first_name,
           s.middle_name,
           s.last_name,
           s.lrn,
           s.grade_level,
           sec.name AS section_name,
           e.school_year,
           e.term,
           e.enrolled_at,
           STRING_AGG(DISTINCT sub.name, ', ' ORDER BY sub.name) AS subjects
    FROM sections sec
    INNER JOIN students s ON s.section_id = sec.id
    INNER JOIN enrollments e
        ON e.student_id = s.id
    LEFT JOIN schedules sch
        ON sch.section_id = sec.id
       AND sch.teacher_id = :teacher_id
       AND sch.school_year = e.school_year
       AND sch.term = e.term
    LEFT JOIN subjects sub ON sub.id = sch.subject_id
    WHERE {$whereSql}
    GROUP BY s.id, s.first_name, s.middle_name, s.last_name, s.lrn, s.grade_level,
             sec.name, e.school_year, e.term, e.enrolled_at
    ORDER BY e.enrolled_at DESC NULLS LAST, sec.name, s.last_name, s.first_name
");
$stmt->execute($params);
$students = $stmt->fetchAll();

$pageTitle = 'My Students';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold"><i class="bi bi-people-fill me-2"></i>My Students</h4>
        <p class="text-muted mb-1">Students appear here after the registrar submits the paid enrollment to your advisory section.</p>
        <div class="small text-muted">Active period: <?= activeAcademicPeriodBadge() ?></div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" class="form-control form-control-sm" name="search" value="<?= e($search) ?>" placeholder="Search by name or LRN...">
            </div>
            <div class="col-md-3">
                <select class="form-select form-select-sm" name="school_year">
                    <?php if (empty($schoolYears)): ?>
                        <option value="<?= e($schoolYear) ?>"><?= e($schoolYear ?: currentSchoolYear()) ?></option>
                    <?php else: ?>
                        <?php foreach ($schoolYears as $year): ?>
                            <option value="<?= e((string)$year) ?>" <?= (string)$year === $schoolYear ? 'selected' : '' ?>><?= e((string)$year) ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" name="term">
                    <?php foreach (academicTermOptions() as $termValue => $termLabel): ?>
                        <option value="<?= e($termValue) ?>" <?= $schoolTerm === $termValue ? 'selected' : '' ?>><?= e($termLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-search me-1"></i>Search</button>
            </div>
            <?php if ($search !== ''): ?>
                <div class="col-md-1">
                    <a href="<?= APP_URL ?>/teacher/teacher-students.php" class="btn btn-sm btn-outline-secondary w-100">Clear</a>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="table-container">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>LRN</th>
                    <th>Grade / Section</th>
                    <th>Subjects With You</th>
                    <th>School Year / Term</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <?= emptyStateRow(6, 'No submitted students found.', 'Students appear here only after payment is verified, the registrar clicks Submit to Adviser, and you are assigned as the adviser of the student section.', 'bi-people') ?>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td class="fw-semibold"><?= e(format_name((string)$student['first_name'], (string)$student['last_name'])) ?></td>
                            <td><?= e((string)($student['lrn'] ?: 'N/A')) ?></td>
                            <td>
                                <?= e(formatGradeLevel((string)$student['grade_level'])) ?>
                                <div class="small text-muted"><?= e((string)$student['section_name']) ?></div>
                            </td>
                            <td><?= e((string)($student['subjects'] ?: 'Section adviser')) ?></td>
                            <td><?= e((string)$student['school_year']) ?> / <?= e((string)$student['term']) ?></td>
                            <td><?= e(!empty($student['enrolled_at']) ? date('M d, Y h:i A', strtotime((string)$student['enrolled_at'])) : '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
