<?php
/**
 * Admin Students — CRUD with search and pagination
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('admin');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo    = getDB();
$search = trim($_GET['search'] ?? '');
$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);
$errors = [];

// ── Handle Delete ───────────────────────────────────────
if ($action === 'delete' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $stmt = $pdo->prepare("DELETE FROM students WHERE id = :id");
    $stmt->execute([':id' => $id]);
    auditLog('delete_student', 'students', $id);
    setFlash('success', 'Student deleted.');
    redirect(APP_URL . '/admin/admin-students.php');
}

// ── Handle Create / Update ──────────────────────────────
if (in_array($action, ['create', 'edit']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $data = [
        'guardian_id'  => (int)($_POST['guardian_id'] ?? 0),
        'full_name'    => trim($_POST['full_name'] ?? ''),
        'birthdate'    => trim($_POST['birthdate'] ?? ''),
        'gender'       => trim($_POST['gender'] ?? ''),
        'grade_level'  => trim($_POST['grade_level'] ?? ''),
        'section_id'   => (int)($_POST['section_id'] ?? 0) ?: null,
        'lrn'          => trim($_POST['lrn'] ?? ''),
    ];

    if (empty($data['full_name'])) $errors[] = 'Full name is required.';
    if (empty($data['guardian_id'])) $errors[] = 'Guardian is required.';

    if (empty($errors)) {
        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO students (guardian_id, full_name, birthdate, gender, grade_level, section_id, lrn) VALUES (:gid, :name, :birth, :gender, :grade, :sec, :lrn)");
            $stmt->execute([':gid' => $data['guardian_id'], ':name' => $data['full_name'], ':birth' => $data['birthdate'] ?: null, ':gender' => $data['gender'] ?: null, ':grade' => $data['grade_level'], ':sec' => $data['section_id'], ':lrn' => $data['lrn'] ?: null]);
            auditLog('create_student', 'students', (int)$pdo->lastInsertId());
            setFlash('success', 'Student created.');
        } else {
            $stmt = $pdo->prepare("UPDATE students SET guardian_id=:gid, full_name=:name, birthdate=:birth, gender=:gender, grade_level=:grade, section_id=:sec, lrn=:lrn WHERE id=:id");
            $stmt->execute([':gid' => $data['guardian_id'], ':name' => $data['full_name'], ':birth' => $data['birthdate'] ?: null, ':gender' => $data['gender'] ?: null, ':grade' => $data['grade_level'], ':sec' => $data['section_id'], ':lrn' => $data['lrn'] ?: null, ':id' => $id]);
            auditLog('update_student', 'students', $id);
            setFlash('success', 'Student updated.');
        }
        redirect(APP_URL . '/admin/admin-students.php');
    }
}

// ── Fetch for edit ──────────────────────────────────────
$editStudent = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $editStudent = $stmt->fetch();
}

// ── List with search & pagination ───────────────────────
$where = '';
$params = [];
if ($search !== '') {
    $where = "WHERE s.full_name LIKE :search OR s.lrn LIKE :search2";
    $params[':search'] = "%{$search}%";
    $params[':search2'] = "%{$search}%";
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM students s {$where}");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
[$offset, $limit, $page, $totalPages] = paginate($total);

$stmt = $pdo->prepare("
    SELECT s.*, g.full_name AS guardian_name, sec.name AS section_name
    FROM students s
    LEFT JOIN guardians g ON s.guardian_id = g.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    {$where}
    ORDER BY s.full_name
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($params);
$students = $stmt->fetchAll();

// Dropdowns
$guardians = $pdo->query("SELECT id, full_name FROM guardians ORDER BY full_name")->fetchAll();
$sections  = $pdo->query("SELECT id, name, grade_level FROM sections ORDER BY grade_level, name")->fetchAll();

$pageTitle = 'Manage Students';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-6">
        <h4 class="fw-bold"><i class="bi bi-people me-2"></i>Students</h4>
    </div>
    <div class="col-md-6 text-md-end">
        <a href="?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Add Student</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (in_array($action, ['create', 'edit'])): ?>
<!-- Create / Edit Form -->
<div class="card mb-4">
    <div class="card-header bg-white fw-bold"><?= $action === 'create' ? 'Add New Student' : 'Edit Student' ?></div>
    <div class="card-body">
        <form method="POST" id="student-form">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="full_name" value="<?= e($editStudent['full_name'] ?? '') ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Guardian <span class="text-danger">*</span></label>
                    <select class="form-select" name="guardian_id" required>
                        <option value="">Select...</option>
                        <?php foreach ($guardians as $g): ?>
                            <option value="<?= $g['id'] ?>" <?= ($editStudent['guardian_id'] ?? 0) == $g['id'] ? 'selected' : '' ?>><?= e($g['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Birthdate</label>
                    <input type="date" class="form-control" name="birthdate" value="<?= e($editStudent['birthdate'] ?? '') ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Gender</label>
                    <select class="form-select" name="gender">
                        <option value="">Select...</option>
                        <?php foreach (['male','female','other'] as $g): ?>
                            <option value="<?= $g ?>" <?= ($editStudent['gender'] ?? '') === $g ? 'selected' : '' ?>><?= ucfirst($g) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Grade Level</label>
                    <select class="form-select" name="grade_level">
                        <option value="">Select...</option>
                        <?php for ($gl = 7; $gl <= 12; $gl++): ?>
                            <option value="<?= $gl ?>" <?= ($editStudent['grade_level'] ?? '') == $gl ? 'selected' : '' ?>>Grade <?= $gl ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Section</label>
                    <select class="form-select" name="section_id">
                        <option value="0">None</option>
                        <?php foreach ($sections as $sec): ?>
                            <option value="<?= $sec['id'] ?>" <?= ($editStudent['section_id'] ?? 0) == $sec['id'] ? 'selected' : '' ?>><?= e($sec['name']) ?> (Grade <?= e($sec['grade_level']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">LRN</label>
                    <input type="text" class="form-control" name="lrn" value="<?= e($editStudent['lrn'] ?? '') ?>">
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
                <a href="<?= APP_URL ?>/admin/admin-students.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Search -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center" id="student-search">
            <div class="col-md-8">
                <input type="text" class="form-control form-control-sm" name="search" placeholder="Search by name or LRN..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
                <?php if ($search): ?><a href="<?= APP_URL ?>/admin/admin-students.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="table-container">
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="students-table">
            <thead>
                <tr><th>#</th><th>Full Name</th><th>Guardian</th><th>Grade Level</th><th>Section</th><th>LRN</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No students found.</td></tr>
                <?php else: ?>
                    <?php foreach ($students as $i => $s): ?>
                    <tr>
                        <td><?= $offset + $i + 1 ?></td>
                        <td class="fw-bold"><?= e($s['full_name']) ?></td>
                        <td><?= e($s['guardian_name'] ?? 'N/A') ?></td>
                        <td>Grade <?= e($s['grade_level'] ?? 'N/A') ?></td>
                        <td><?= e($s['section_name'] ?? 'N/A') ?></td>
                        <td><small><?= e($s['lrn'] ?? 'N/A') ?></small></td>
                        <td>
                            <a href="?action=edit&id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="?action=delete&id=<?= $s['id'] ?>" class="d-inline" onsubmit="return confirm('Delete this student?')">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= paginationLinks($page, $totalPages, '?search=' . urlencode($search)) ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
