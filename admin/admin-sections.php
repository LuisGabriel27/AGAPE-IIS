<?php
/**
 * Admin Sections — CRUD: name, grade level, adviser, capacity
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
    $pdo->prepare("DELETE FROM sections WHERE id = :id")->execute([':id' => $id]);
    auditLog('delete_section', 'sections', $id);
    setFlash('success', 'Section deleted.');
    redirect(APP_URL . '/admin/admin-sections.php');
}

if (in_array($action, ['create', 'edit']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $name       = trim($_POST['name'] ?? '');
    $gradeLevel = trim($_POST['grade_level'] ?? '');
    $adviserId  = (int)($_POST['adviser_id'] ?? 0) ?: null;
    $capacity   = (int)($_POST['capacity'] ?? 40);

    if (empty($name)) $errors[] = 'Section name is required.';
    if (empty($gradeLevel)) $errors[] = 'Grade level is required.';

    if (empty($errors)) {
        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO sections (name, grade_level, adviser_id, capacity) VALUES (:n, :g, :a, :c) RETURNING id");
            $stmt->execute([':n' => $name, ':g' => $gradeLevel, ':a' => $adviserId, ':c' => $capacity]);
            auditLog('create_section', 'sections', (int)$stmt->fetchColumn());
            setFlash('success', 'Section created.');
        } else {
            $stmt = $pdo->prepare("UPDATE sections SET name=:n, grade_level=:g, adviser_id=:a, capacity=:c WHERE id=:id");
            $stmt->execute([':n' => $name, ':g' => $gradeLevel, ':a' => $adviserId, ':c' => $capacity, ':id' => $id]);
            auditLog('update_section', 'sections', $id);
            setFlash('success', 'Section updated.');
        }
        redirect(APP_URL . '/admin/admin-sections.php');
    }
}

$editSection = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM sections WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $editSection = $stmt->fetch();
}

$teachersList = $pdo->query("SELECT id, first_name, last_name FROM teachers ORDER BY last_name, first_name")->fetchAll();

$stmt = $pdo->query("
    SELECT s.*, CASE WHEN t.first_name = '' THEN t.last_name ELSE t.last_name || ', ' || t.first_name END AS adviser_name,
           (SELECT COUNT(*) FROM students st WHERE st.section_id = s.id) AS enrolled
    FROM sections s LEFT JOIN teachers t ON s.adviser_id = t.id
    ORDER BY s.grade_level, s.name
");
$sections = $stmt->fetchAll();

$pageTitle = 'Manage Sections';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-6"><h4 class="fw-bold"><i class="bi bi-grid me-2"></i>Sections</h4></div>
    <div class="col-md-6 text-md-end"><a href="?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Add Section</a></div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (in_array($action, ['create', 'edit'])): ?>
<div class="card mb-4">
    <div class="card-header bg-white fw-bold"><?= e($action === 'create' ? 'Add Section' : 'Edit Section') ?></div>
    <div class="card-body">
        <form method="POST" id="section-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" value="<?= e($editSection['name'] ?? '') ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Grade Level <span class="text-danger">*</span></label>
                    <select class="form-select" name="grade_level" required>
                        <option value="">Select...</option>
                        <?php foreach (basicEducationGradeLevels() as $g => $label): ?>
                            <option value="<?= e($g) ?>" <?= e(($editSection['grade_level'] ?? '') == $g ? 'selected' : '') ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Adviser</label>
                    <select class="form-select" name="adviser_id">
                        <option value="0">None</option>
                        <?php foreach ($teachersList as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= e(($editSection['adviser_id'] ?? 0) == $t['id'] ? 'selected' : '') ?>><?= e(format_name($t['first_name'], $t['last_name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">Capacity</label>
                    <input type="number" class="form-control" name="capacity" value="<?= e((string)($editSection['capacity'] ?? 40)) ?>" min="1">
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
            <a href="<?= APP_URL ?>/admin/admin-sections.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="table-container"><div class="table-responsive">
    <table class="table table-hover mb-0" id="sections-table">
        <thead><tr><th>Name</th><th>Grade Level</th><th>Adviser</th><th class="text-center">Capacity</th><th class="text-center">Enrolled</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if (empty($sections)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No sections found.</td></tr>
            <?php else: foreach ($sections as $s): ?>
            <tr>
                <td class="fw-bold"><?= e($s['name']) ?></td>
                <td><?= e(formatGradeLevel((string)$s['grade_level'])) ?></td>
                <td><?= e($s['adviser_name'] ?? 'None') ?></td>
                <td class="text-center"><?= e((string)$s['capacity']) ?></td>
                <td class="text-center"><?= e((string)$s['enrolled']) ?></td>
                <td>
                    <a href="?action=edit&id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                    <form method="POST" action="?action=delete&id=<?= (int)$s['id'] ?>" class="d-inline" onsubmit="return confirm('Delete?')">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
