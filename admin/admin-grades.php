<?php
/**
 * Admin Grades - View and override DepEd periodic grades with audit trail.
 * Admin can also toggle published status.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $postAction = $_POST['form_action'] ?? 'override';

    if ($postAction === 'toggle_publish') {
        $gradeId = (int)($_POST['grade_id'] ?? 0);
        if ($gradeId) {
            $stmt = $pdo->prepare("SELECT published FROM grades WHERE id = :id");
            $stmt->execute([':id' => $gradeId]);
            $currentPub = (int)$stmt->fetchColumn();
            $newPub = $currentPub ? 0 : 1;

            $stmt = $pdo->prepare("UPDATE grades SET published = :pub WHERE id = :id");
            $stmt->execute([':pub' => $newPub, ':id' => $gradeId]);

            auditLog($newPub ? 'grade_published' : 'grade_unpublished', 'grades', $gradeId);
            setFlash('success', $newPub ? 'Grade published.' : 'Grade unpublished.');
            redirect(APP_URL . '/admin/admin-grades.php?student_id=' . $filterStudent . '&school_year=' . urlencode($filterYear));
        }
    }

    if ($postAction === 'override') {
        $gradeId = (int)($_POST['grade_id'] ?? 0);
        $quarterGrades = [];
        foreach (array_keys(gradingPeriods()) as $column) {
            $value = $_POST[$column] ?? '';
            $quarterGrades[$column] = is_numeric($value) ? (float)$value : null;
            if ($quarterGrades[$column] !== null && ($quarterGrades[$column] < 0 || $quarterGrades[$column] > 100)) {
                $errors[] = 'Grades must be between 0 and 100.';
            }
        }

        if ($gradeId && empty($errors)) {
            $stmt = $pdo->prepare("SELECT quarter1, quarter2, quarter3, quarter4, final_grade FROM grades WHERE id = :id");
            $stmt->execute([':id' => $gradeId]);
            $oldData = $stmt->fetch();

            $finalGrade = finalRatingFromQuarterGrades($quarterGrades);
            $stmt = $pdo->prepare("
                UPDATE grades
                SET quarter1 = :q1,
                    quarter2 = :q2,
                    quarter3 = :q3,
                    quarter4 = :q4,
                    final_grade = :fg,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':q1' => $quarterGrades['quarter1'],
                ':q2' => $quarterGrades['quarter2'],
                ':q3' => $quarterGrades['quarter3'],
                ':q4' => $quarterGrades['quarter4'],
                ':fg' => $finalGrade,
                ':id' => $gradeId,
            ]);

            auditLog('grade_override', 'grades', $gradeId, $oldData, $quarterGrades + ['final_grade' => $finalGrade]);
            setFlash('success', 'Grade overridden successfully.');
            redirect(APP_URL . '/admin/admin-grades.php?student_id=' . $filterStudent . '&school_year=' . urlencode($filterYear));
        }
    }
}

$allStudents = $pdo->query("SELECT id, first_name, last_name FROM students ORDER BY last_name, first_name")->fetchAll();
$years = $pdo->query("SELECT DISTINCT school_year FROM grades ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($years)) {
    $years = [currentSchoolYear()];
}

$grades = [];
if ($filterStudent) {
    $where = "WHERE g.student_id = :sid";
    $params = [':sid' => $filterStudent];
    if ($filterYear) {
        $where .= " AND g.school_year = :sy";
        $params[':sy'] = $filterYear;
    }

    $stmt = $pdo->prepare("
        SELECT g.*, sub.name AS subject_name, sub.code AS subject_code,
               CASE WHEN t.first_name = '' THEN t.last_name ELSE t.last_name || ', ' || t.first_name END AS teacher_name
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

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="card mb-4"><div class="card-body">
    <form method="GET" class="row g-3 align-items-end" id="grades-filter">
        <div class="col-md-5">
            <label class="form-label">Student</label>
            <select class="form-select" name="student_id" required>
                <option value="">Select student...</option>
                <?php foreach ($allStudents as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= e($filterStudent == $s['id'] ? 'selected' : '') ?>><?= e(format_name($s['first_name'], $s['last_name'])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">School Year</label>
            <select class="form-select" name="school_year">
                <option value="">All</option>
                <?php foreach ($years as $y): ?>
                    <option value="<?= e($y) ?>" <?= e($filterYear === $y ? 'selected' : '') ?>><?= e($y) ?></option>
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
            <tr>
                <th>Subject</th>
                <th>School Year</th>
                <th class="text-center">1st</th>
                <th class="text-center">2nd</th>
                <th class="text-center">3rd</th>
                <th class="text-center">4th</th>
                <th class="text-center">Final Rating</th>
                <th class="text-center">Descriptor</th>
                <th>Submitted By</th>
                <th class="text-center">Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($grades as $g): ?>
            <?php $final = $g['final_grade'] !== null ? (float)$g['final_grade'] : null; ?>
            <tr>
                <td><span class="badge bg-secondary"><?= e($g['subject_code']) ?></span> <?= e($g['subject_name']) ?></td>
                <td><?= e($g['school_year']) ?></td>
                <td class="text-center"><?= e($g['quarter1'] !== null ? number_format((float)$g['quarter1'], 2) : '-') ?></td>
                <td class="text-center"><?= e($g['quarter2'] !== null ? number_format((float)$g['quarter2'], 2) : '-') ?></td>
                <td class="text-center"><?= e($g['quarter3'] !== null ? number_format((float)$g['quarter3'], 2) : '-') ?></td>
                <td class="text-center"><?= e($g['quarter4'] !== null ? number_format((float)$g['quarter4'], 2) : '-') ?></td>
                <td class="text-center fw-bold"><?= e($final !== null ? number_format($final, 2) : 'Pending') ?></td>
                <td class="text-center"><?= e(depedDescriptor($final)) ?></td>
                <td><small><?= e($g['teacher_name'] ?? 'N/A') ?></small></td>
                <td class="text-center">
                    <?php if ((int)($g['published'] ?? 0)): ?>
                        <span class="badge bg-success"><i class="bi bi-lock-fill me-1"></i>Published</span>
                    <?php else: ?>
                        <span class="badge bg-secondary"><i class="bi bi-pencil me-1"></i>Draft</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editGrade<?= (int)$g['id'] ?>">
                            <i class="bi bi-pencil"></i> Override
                        </button>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="form_action" value="toggle_publish">
                            <input type="hidden" name="grade_id" value="<?= (int)$g['id'] ?>">
                            <?php if ((int)($g['published'] ?? 0)): ?>
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Unpublish"><i class="bi bi-unlock"></i></button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-sm btn-outline-success" title="Publish"><i class="bi bi-lock"></i></button>
                            <?php endif; ?>
                        </form>
                    </div>
                </td>
            </tr>

            <div class="modal fade" id="editGrade<?= (int)$g['id'] ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h6 class="modal-title">Override: <?= e($g['subject_name']) ?></h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="form_action" value="override">
                                <input type="hidden" name="grade_id" value="<?= (int)$g['id'] ?>">
                                <div class="row">
                                    <?php foreach (gradingPeriods() as $column => $label): ?>
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label small"><?= e($label) ?></label>
                                            <input type="number" class="form-control form-control-sm" name="<?= e($column) ?>" step="0.01" min="0" max="100" value="<?= e((string)($g[$column] ?? '')) ?>">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="alert alert-info small mb-0">Final Rating is computed only after all four grading periods are encoded.</div>
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
