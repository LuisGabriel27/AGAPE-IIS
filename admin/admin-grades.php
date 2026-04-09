<?php
/**
 * Admin Grades — View and override any student's grades with audit trail
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('admin');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$filterStudent = (int)($_GET['student_id'] ?? 0);
$filterYear    = $_GET['school_year'] ?? '';
$errors        = [];

// ── Handle grade override ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $gradeId = (int)($_POST['grade_id'] ?? 0);
    $midterm = is_numeric($_POST['midterm'] ?? '') ? (float)$_POST['midterm'] : null;
    $finals  = is_numeric($_POST['finals'] ?? '')  ? (float)$_POST['finals']  : null;
    $finalGrade = ($midterm !== null && $finals !== null) ? round(($midterm + $finals) / 2, 2) : null;

    if ($gradeId) {
        // Get old values for audit
        $stmt = $pdo->prepare("SELECT midterm, finals, final_grade FROM grades WHERE id = :id");
        $stmt->execute([':id' => $gradeId]);
        $oldData = $stmt->fetch();

        $stmt = $pdo->prepare("UPDATE grades SET midterm = :m, finals = :f, final_grade = :fg, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':m' => $midterm, ':f' => $finals, ':fg' => $finalGrade, ':id' => $gradeId]);

        auditLog('grade_override', 'grades', $gradeId, $oldData, ['midterm' => $midterm, 'finals' => $finals, 'final_grade' => $finalGrade]);
        setFlash('success', 'Grade overridden successfully.');
        redirect(APP_URL . '/admin/admin-grades.php?student_id=' . $filterStudent . '&school_year=' . urlencode($filterYear));
    }
}

// Students dropdown
$allStudents = $pdo->query("SELECT id, full_name FROM students ORDER BY full_name")->fetchAll();
$years = $pdo->query("SELECT DISTINCT school_year FROM grades ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($years)) $years = [currentSchoolYear()];

// Fetch grades
$grades = [];
if ($filterStudent) {
    $where = "WHERE g.student_id = :sid";
    $params = [':sid' => $filterStudent];
    if ($filterYear) { $where .= " AND g.school_year = :sy"; $params[':sy'] = $filterYear; }

    $stmt = $pdo->prepare("
        SELECT g.*, sub.name AS subject_name, sub.code AS subject_code,
               t.full_name AS teacher_name
        FROM grades g
        JOIN subjects sub ON g.subject_id = sub.id
        LEFT JOIN teachers t ON g.submitted_by = t.id
        {$where}
        ORDER BY g.school_year DESC, sub.name
    ");
    $stmt->execute($params);
    $grades = $stmt->fetchAll();
}

$pageTitle = 'Manage Grades';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12"><h4 class="fw-bold"><i class="bi bi-card-checklist me-2"></i>Grades Management</h4></div>
</div>

<!-- Filters -->
<div class="card mb-4"><div class="card-body">
    <form method="GET" class="row g-3 align-items-end" id="grades-filter">
        <div class="col-md-5">
            <label class="form-label">Student</label>
            <select class="form-select" name="student_id" required>
                <option value="">Select student...</option>
                <?php foreach ($allStudents as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $filterStudent == $s['id'] ? 'selected' : '' ?>><?= e($s['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">School Year</label>
            <select class="form-select" name="school_year">
                <option value="">All</option>
                <?php foreach ($years as $y): ?>
                    <option value="<?= e($y) ?>" <?= $filterYear === $y ? 'selected' : '' ?>><?= e($y) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-filter me-1"></i>View</button></div>
    </form>
</div></div>

<?php if ($filterStudent && !empty($grades)): ?>
<div class="table-container"><div class="table-responsive">
    <table class="table table-hover mb-0" id="admin-grades-table">
        <thead>
            <tr><th>Subject</th><th>Year/Term</th><th class="text-center">Midterm</th><th class="text-center">Finals</th><th class="text-center">Final</th><th>Submitted By</th><th>Actions</th></tr>
        </thead>
        <tbody>
            <?php foreach ($grades as $g): ?>
            <tr>
                <td><span class="badge bg-secondary"><?= e($g['subject_code']) ?></span> <?= e($g['subject_name']) ?></td>
                <td><?= e($g['school_year']) ?> — <?= e($g['term']) ?></td>
                <td class="text-center"><?= $g['midterm'] !== null ? number_format($g['midterm'], 2) : '—' ?></td>
                <td class="text-center"><?= $g['finals'] !== null ? number_format($g['finals'], 2) : '—' ?></td>
                <td class="text-center fw-bold"><?= $g['final_grade'] !== null ? number_format($g['final_grade'], 2) : '—' ?></td>
                <td><small><?= e($g['teacher_name'] ?? 'N/A') ?></small></td>
                <td>
                    <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editGrade<?= $g['id'] ?>">
                        <i class="bi bi-pencil"></i> Override
                    </button>
                </td>
            </tr>

            <!-- Override Modal -->
            <div class="modal fade" id="editGrade<?= $g['id'] ?>" tabindex="-1">
                <div class="modal-dialog modal-sm">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h6 class="modal-title">Override: <?= e($g['subject_name']) ?></h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="grade_id" value="<?= $g['id'] ?>">
                                <div class="mb-2">
                                    <label class="form-label small">Midterm</label>
                                    <input type="number" class="form-control form-control-sm" name="midterm" step="0.01" min="50" max="100" value="<?= $g['midterm'] ?? '' ?>">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Finals</label>
                                    <input type="number" class="form-control form-control-sm" name="finals" step="0.01" min="50" max="100" value="<?= $g['finals'] ?? '' ?>">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="submit" class="btn btn-sm btn-warning">Override</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<?php elseif ($filterStudent): ?>
    <div class="alert alert-info">No grades found for this student.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
