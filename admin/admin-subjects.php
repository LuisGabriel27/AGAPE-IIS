<?php
/**
 * Admin Subjects - CRUD for basic education subjects by grade level.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('admin');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo    = getDB();
$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);
$errors = [];

if ($action === 'delete' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $pdo->prepare("DELETE FROM subjects WHERE id = :id")->execute([':id' => $id]);
    auditLog('delete_subject', 'subjects', $id);
    setFlash('success', 'Subject deleted.');
    redirect(APP_URL . '/admin/admin-subjects.php');
}

if (in_array($action, ['create', 'edit']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $gradeLevel = trim($_POST['grade_level'] ?? '');
    $units = 0; // Kept only for legacy database compatibility.
    $dept = null; // Kept only for legacy database compatibility.

    if (empty($code)) $errors[] = 'Subject code is required.';
    if (empty($name)) $errors[] = 'Subject name is required.';
    if (empty($gradeLevel) || !array_key_exists($gradeLevel, basicEducationGradeLevels())) $errors[] = 'Grade level is required.';

    if (empty($errors)) {
        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO subjects (code, name, grade_level, units, department) VALUES (:c, :n, :g, :u, :d) RETURNING id");
            $stmt->execute([':c' => $code, ':n' => $name, ':g' => $gradeLevel, ':u' => $units, ':d' => $dept]);
            auditLog('create_subject', 'subjects', (int)$stmt->fetchColumn());
            setFlash('success', 'Subject created.');
        } else {
            $stmt = $pdo->prepare("UPDATE subjects SET code=:c, name=:n, grade_level=:g, units=:u, department=:d WHERE id=:id");
            $stmt->execute([':c' => $code, ':n' => $name, ':g' => $gradeLevel, ':u' => $units, ':d' => $dept, ':id' => $id]);
            auditLog('update_subject', 'subjects', $id);
            setFlash('success', 'Subject updated.');
        }
        redirect(APP_URL . '/admin/admin-subjects.php');
    }
}

$editSubject = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $editSubject = $stmt->fetch();
}

$subjects = $pdo->query("
    SELECT *
    FROM subjects
    ORDER BY CASE grade_level
        WHEN 'Preschool' THEN 0
        WHEN 'Kindergarten' THEN 1
        WHEN '1' THEN 2
        WHEN '2' THEN 3
        WHEN '3' THEN 4
        WHEN '4' THEN 5
        WHEN '5' THEN 6
        WHEN '6' THEN 7
        ELSE 99
    END, code
")->fetchAll();

$pageTitle = 'Manage Subjects';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-6"><h4 class="fw-bold"><i class="bi bi-book me-2"></i>Subjects</h4></div>
    <div class="col-md-6 text-md-end"><a href="?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Add Subject</a></div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (in_array($action, ['create', 'edit'])): ?>
<div class="card mb-4">
    <div class="card-header bg-white fw-bold"><?= e($action === 'create' ? 'Add Subject' : 'Edit Subject') ?></div>
    <div class="card-body">
        <form method="POST" id="subject-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Code <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="code" value="<?= e($editSubject['code'] ?? '') ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" value="<?= e($editSubject['name'] ?? '') ?>" required>
                </div>
                <div class="col-md-5 mb-3">
                    <label class="form-label">Grade Level <span class="text-danger">*</span></label>
                    <select class="form-select" name="grade_level" required>
                        <option value="">Select...</option>
                        <?php foreach (basicEducationGradeLevels() as $levelValue => $levelLabel): ?>
                            <option value="<?= e($levelValue) ?>" <?= e(($editSubject['grade_level'] ?? '') === $levelValue ? 'selected' : '') ?>>
                                <?= e($levelLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
            <a href="<?= APP_URL ?>/admin/admin-subjects.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="table-container"><div class="table-responsive">
    <table class="table table-hover mb-0" id="subjects-table">
        <thead><tr><th>Grade Level</th><th>Code</th><th>Subject Name</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if (empty($subjects)): ?>
                <?= emptyStateRow(4, 'No subjects created yet.', 'Use the form above to add subjects for each grade level before building schedules or encoding grades.', 'bi-journal-bookmark') ?>
            <?php else: foreach ($subjects as $s): ?>
            <tr>
                <td><?= e(formatGradeLevel($s['grade_level'] ?? '')) ?></td>
                <td><span class="badge bg-secondary"><?= e($s['code']) ?></span></td>
                <td class="fw-bold"><?= e($s['name']) ?></td>
                <td>
                    <a href="?action=edit&id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit subject" aria-label="Edit subject"><i class="bi bi-pencil"></i></a>
                    <form method="POST" action="?action=delete&id=<?= (int)$s['id'] ?>" class="d-inline" data-confirm="Delete this subject? This cannot be undone." data-confirm-variant="danger">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <button class="btn btn-sm btn-outline-danger" title="Delete subject" aria-label="Delete subject"><i class="bi bi-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
